<?php

declare(strict_types=1);

namespace app\components;

use app\models\Project;

/**
 * The note of the project page on what the web server could not read of the
 * project directory (ProjectFilesystemScanner::unreadable()), or could not
 * reach at all. Before, such a path made the page answer 500.
 */
final class ProjectFilesNote
{
    /**
     * A manual project whose directory the web server cannot find, or
     * cannot reach as it may not enter a directory above it; sprintf(): the
     * directory as configured.
     */
    public const UNREACHABLE_DIRECTORY = 'The web server cannot find the project directory %s, or may not enter a '
        . 'directory above it. Check that it exists, and give the web server\'s user permission to enter every '
        . 'directory on its path.';

    /** The web server could not list the project directory, and found nothing in it. */
    public const UNREADABLE_DIRECTORY = 'The web server cannot read the project directory, so its playbooks and '
        . 'repository structure are not shown. Give the web server\'s user permission to read and enter it.';

    /**
     * The web server could not read paths in the project directory.
     * sprintf(): the paths, relative to the project directory, separated by
     * commas, at most SHOWN of them (MORE).
     */
    public const UNREADABLE_PATHS = 'The web server cannot read these paths in the project directory, so the '
        . 'playbooks and the repository structure below may be incomplete: %s. Give the web server\'s user '
        . 'permission to read them.';

    /** How UNREADABLE_PATHS names the project directory itself. */
    public const ITSELF = 'the project directory itself';

    /** What UNREADABLE_PATHS ends its list with beyond SHOWN paths; sprintf(): how many more. */
    public const MORE = 'and %d more';

    /** How many unreadable paths UNREADABLE_PATHS names. */
    public const SHOWN = 10;

    /**
     * The note, or null when there is nothing to say. None while the project
     * syncs: a sync replaces files while the page reads them, and those are
     * no permission problem.
     *
     * @param string|null $directory the project directory the page read, null when there is none
     * @param list<string> $unreadable ProjectFilesystemScanner::unreadable() of $directory
     * @param bool $foundAny whether the page found playbooks or entries all the same
     */
    public static function of(Project $project, ?string $directory, array $unreadable, bool $foundAny): ?string
    {
        if ($project->status === Project::STATUS_SYNCING) {
            return null;
        }
        if ($directory === null) {
            $configured = (string)$project->local_path;

            return $project->scm_type === Project::SCM_TYPE_MANUAL && $configured !== ''
                ? sprintf(self::UNREACHABLE_DIRECTORY, $configured)
                : null;
        }
        if ($unreadable === []) {
            return null;
        }
        if (in_array('.', $unreadable, true) && !$foundAny) {
            return self::UNREADABLE_DIRECTORY;
        }

        return sprintf(self::UNREADABLE_PATHS, self::paths($unreadable));
    }

    /**
     * @param list<string> $unreadable
     */
    private static function paths(array $unreadable): string
    {
        $named = array_map(static fn (string $path): string => $path === '.' ? self::ITSELF : $path, $unreadable);
        $shown = array_slice($named, 0, self::SHOWN);
        $more = count($named) - count($shown);
        if ($more > 0) {
            $shown[] = sprintf(self::MORE, $more);
        }

        return implode(', ', $shown);
    }
}
