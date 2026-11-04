<?php

declare(strict_types=1);

namespace app\components;

/**
 * Absolute links to the pages of Ansilume, for what leaves it, such as a
 * notification: the base is params.appBaseUrl (APP_URL), the URL that the
 * operator gave for Ansilume, and the path the page's route as the web
 * application's URL manager reads it (pretty URLs; no rule names these
 * pages).
 *
 * They are built without Url::to() and the URL manager, which throw in the
 * console application: the notification worker, the queue worker, the
 * maintenance run and every other console command build the same links as
 * a web request. The base is appBaseUrl alone, never the host of a web
 * request, which for a runner's report is the internal nginx: without
 * appBaseUrl (an empty APP_URL=) a link is a path without an address, in
 * every process.
 */
final class AppLinks
{
    /** The page of a workflow run; sprintf(): its ID. */
    public const WORKFLOW_RUN = '/workflow-job/view?id=%d';

    /** The page of a job; sprintf(): its ID. */
    public const JOB = '/job/view?id=%d';

    /** The page of an approval request; sprintf(): its ID. */
    public const APPROVAL = '/approval/view?id=%d';

    /** The page of a project; sprintf(): its ID. */
    public const PROJECT = '/project/view?id=%d';

    /** The page of a schedule; sprintf(): its ID. */
    public const SCHEDULE = '/schedule/view?id=%d';

    /**
     * The base of every link, without a trailing slash: appBaseUrl; ''
     * without it, whose links are paths.
     */
    public static function base(): string
    {
        return rtrim((string)(\Yii::$app->params['appBaseUrl'] ?? ''), '/');
    }

    /** The page of workflow run #$id. */
    public static function workflowRun(int $id): string
    {
        return self::base() . sprintf(self::WORKFLOW_RUN, $id);
    }

    /** The page of job #$id. */
    public static function job(int $id): string
    {
        return self::base() . sprintf(self::JOB, $id);
    }

    /** The page of approval request #$id. */
    public static function approval(int $id): string
    {
        return self::base() . sprintf(self::APPROVAL, $id);
    }
}
