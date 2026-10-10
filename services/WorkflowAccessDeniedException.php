<?php

declare(strict_types=1);

namespace app\services;

/**
 * A workflow action refused by team scoping: the acting user (or the user a
 * trigger runs as) may not operate every job step's project. Nothing was
 * written. Extends RuntimeException so existing catch blocks still report it;
 * the web and API layers answer 403.
 */
final class WorkflowAccessDeniedException extends \RuntimeException
{
    /**
     * @param list<int> $jobTemplateIds the job templates the user may not use;
     *     empty when the user may not even see the workflow, so that a refusal
     *     reveals no job template of another team
     */
    public function __construct(string $message, public readonly array $jobTemplateIds = [])
    {
        parent::__construct($message);
    }
}
