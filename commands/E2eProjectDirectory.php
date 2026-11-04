<?php

declare(strict_types=1);

namespace app\commands;

use app\helpers\FileHelper;

/**
 * The directory of e2e-project, the manual project of e2e-template: a
 * minimal Ansible project on disk, so that lint, sync and template runs
 * have something real to point at (projects/sync-lint.spec.ts lints it;
 * without a playbook Run Lint only hits the "no local path" guard).
 *
 * It lives in the runtime directory (DIRECTORY): the lint runs that saves
 * and Run Lint queue run in the queue-worker, which does not see the temp
 * directory of the app container, while in a git checkout both bind-mount
 * the repository at /var/www (the runners of the dev stack too). It gets
 * the owner of the runtime directory (E2eFixtureHelper::runtimeOwner()), so
 * the queue-worker's user may write ansible-lint's cache there and the user
 * of the checkout can remove it. Every seed writes the playbook when it is
 * missing (create()); the E2E teardown removes the directory (remove()).
 */
final class E2eProjectDirectory
{
    /** Where the directory goes: seen by the app and the queue-worker. */
    public const DIRECTORY = '@runtime/e2e-project';

    /** The playbook, site.yml: one task on localhost that changes nothing. */
    public const PLAYBOOK = "---\n- name: E2E smoke test\n  hosts: localhost\n  gather_facts: false\n  tasks:\n"
        . "    - name: Noop\n      ansible.builtin.debug:\n        msg: \"E2E lint target\"\n";

    /** @var callable(string): void */
    private $logger;

    /**
     * @param callable(string): void $logger
     */
    public function __construct(callable $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Creates the directory and its playbook when they are missing, with the
     * owner of the runtime directory; its path.
     */
    public function create(): string
    {
        $path = (string)\Yii::getAlias(self::DIRECTORY);
        if (!is_dir($path)) {
            mkdir($path, 0o755, true);
        }
        $playbook = $path . '/site.yml';
        if (!file_exists($playbook)) {
            file_put_contents($playbook, self::PLAYBOOK);
        }
        $owner = E2eFixtureHelper::runtimeOwner();
        if ($owner !== null) {
            foreach ([$path, $playbook] as $file) {
                chown($file, $owner['uid']);
                chgrp($file, $owner['gid']);
            }
        }

        return $path;
    }

    /**
     * Removes the directory, and says so; nothing when it is gone already.
     */
    public function remove(): void
    {
        $path = (string)\Yii::getAlias(self::DIRECTORY);
        if (is_dir($path)) {
            FileHelper::removeDirectory($path);
            ($this->logger)("  Deleted e2e project directory {$path}.\n");
        }
    }
}
