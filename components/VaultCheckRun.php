<?php

declare(strict_types=1);

namespace app\components;

use app\components\vault\TemplateVaultFiles;
use app\components\vault\VaultEnvelope;
use app\models\Inventory;
use app\models\JobTemplate;
use app\models\Project;
use app\models\ProjectVaultEntry;

/**
 * One run of vault checks (VaultCheckService::batch()): a time budget that
 * every template checked in the run shares, and what the run has already
 * read, so templates that share files and passwords cost little more than
 * one.
 *
 * - The entries of a project's scan and the vars_files of each playbook are
 *   loaded once.
 * - A file is read once for all of its entries, and not again for an entry
 *   this run already found unchanged and checked with the same password.
 * - Whether a password opens an envelope (a PBKDF2 derivation of about 3 ms)
 *   is computed once.
 *
 * Expensive steps, reading a file and deriving a key, first check the time
 * budget and throw VaultCheckTimeout once it is used up. Plaintext is never
 * produced; neither the password nor a key derived from it is kept.
 */
final class VaultCheckRun
{
    /** hrtime() nanoseconds at which the budget is used up. */
    private readonly int $deadline;

    /** @var array<string, list<ProjectVaultEntry>> project id and scan time => entries */
    private array $entries = [];

    /** @var array<string, list<string>> checkout and playbook => vars_files */
    private array $varsFiles = [];

    /** @var array<string, true> entries this run found unchanged in the checkout */
    private array $unchanged = [];

    /**
     * Secret and fingerprint => whether the password opens the vault. Bounded
     * by the time budget: a run derives at most a few thousand keys.
     *
     * @var array<string, bool>
     */
    private array $opens = [];

    public function __construct(float $seconds)
    {
        $this->deadline = (int)hrtime(true) + (int)($seconds * 1000000000);
    }

    private function expired(): bool
    {
        return (int)hrtime(true) >= $this->deadline;
    }

    /**
     * Every entry of the project's last scan, readable or not, by path and line.
     *
     * @return list<ProjectVaultEntry>
     */
    private function entries(Project $project): array
    {
        $key = $project->id . ':' . $project->vault_scanned_at;
        if (!isset($this->entries[$key])) {
            /** @var list<ProjectVaultEntry> $entries */
            $entries = ProjectVaultEntry::find()
                ->where(['project_id' => $project->id])
                ->orderBy(['path' => SORT_ASC, 'line' => SORT_ASC])
                ->all();
            $this->entries[$key] = $entries;
        }

        return $this->entries[$key];
    }

    /**
     * The entries of the project's scan the template probably loads
     * (TemplateVaultFiles), readable or not.
     *
     * @return list<ProjectVaultEntry>
     */
    public function relevantEntries(JobTemplate $template, Project $project, string $root): array
    {
        $entries = $this->entries($project);
        if ($entries === []) {
            return [];
        }
        /** @var Inventory|null $inventory */
        $inventory = Inventory::findOne($template->inventory_id);
        $playbook = (string)$template->playbook;
        $selected = array_flip(TemplateVaultFiles::select(
            $root,
            array_values(array_unique(array_map(static fn (ProjectVaultEntry $entry): string => $entry->path, $entries))),
            $playbook,
            (string)($inventory->inventory_type ?? Inventory::TYPE_STATIC),
            $inventory?->source_path,
            $this->varsFiles[$root . "\0" . $playbook] ??= TemplateVaultFiles::playbookVarsFiles($root, $playbook)
        ));

        return array_values(array_filter($entries, static fn (ProjectVaultEntry $entry): bool => isset($selected[$entry->path])));
    }

    /**
     * The entries the password does not open.
     *
     * @param list<ProjectVaultEntry> $entries readable entries (no error)
     * @param string $secretKey identifies the password: the credential and its
     *        stored, encrypted secret, so a changed secret is checked again
     * @return list<ProjectVaultEntry>|null null when the checkout changed since the scan
     * @throws VaultCheckTimeout when the time budget is used up
     */
    public function unopened(string $root, array $entries, string $secretKey, string $password): ?array
    {
        $unopened = [];
        foreach (self::byPath($entries) as $path => $group) {
            $results = $this->known($root, $group, $secretKey) ?? $this->check($root, (string)$path, $group, $secretKey, $password);
            if ($results === null) {
                return null;
            }
            foreach ($group as $index => $entry) {
                if (!$results[$index]) {
                    $unopened[] = $entry;
                }
            }
        }

        return $unopened;
    }

    /**
     * The results for a file's entries when this run already has all of
     * them: each entry found unchanged and checked with this password.
     *
     * @param list<ProjectVaultEntry> $group
     * @return list<bool>|null
     */
    private function known(string $root, array $group, string $secretKey): ?array
    {
        $results = [];
        foreach ($group as $entry) {
            $result = $this->opens[$secretKey . "\0" . $entry->fingerprint] ?? null;
            if ($result === null || !isset($this->unchanged[self::location($root, $entry)])) {
                return null;
            }
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Reads the file once and checks each of its entries.
     *
     * @param list<ProjectVaultEntry> $group entries of the file $path
     * @return list<bool>|null null when the file changed since the scan
     */
    private function check(string $root, string $path, array $group, string $secretKey, string $password): ?array
    {
        $this->guardTime();
        $envelopes = VaultEntryReader::envelopes($root, $path, $group);
        $results = [];
        foreach ($group as $index => $entry) {
            $envelope = $envelopes[$index];
            if ($envelope === null) {
                return null;
            }
            $this->unchanged[self::location($root, $entry)] = true;
            $results[] = $this->opens($envelope, $secretKey, $password);
        }

        return $results;
    }

    private function opens(VaultEnvelope $envelope, string $secretKey, string $password): bool
    {
        $key = $secretKey . "\0" . $envelope->fingerprint();
        if (!isset($this->opens[$key])) {
            $this->guardTime();
            $this->opens[$key] = $envelope->opens($password);
        }

        return $this->opens[$key];
    }

    /**
     * @throws VaultCheckTimeout
     */
    private function guardTime(): void
    {
        if ($this->expired()) {
            throw new VaultCheckTimeout('The vault check ran out of time.');
        }
    }

    /**
     * @param list<ProjectVaultEntry> $entries
     * @return array<array-key, list<ProjectVaultEntry>> by path, in the order of first appearance
     */
    private static function byPath(array $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $groups[$entry->path][] = $entry;
        }

        return $groups;
    }

    private static function location(string $root, ProjectVaultEntry $entry): string
    {
        return $root . "\0" . $entry->path . "\0" . (int)$entry->line . "\0" . $entry->fingerprint;
    }
}
