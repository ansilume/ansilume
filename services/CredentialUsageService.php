<?php

declare(strict_types=1);

namespace app\services;

use app\components\CredentialUsage;
use app\models\Credential;
use app\models\Job;
use app\models\JobTemplate;
use app\models\Project;
use yii\base\Component;
use yii\db\ActiveQuery;
use yii\db\Query;

/**
 * Finds where a credential is used: job templates (primary or additional),
 * projects (SCM credential) and jobs that have not started yet.
 *
 * Totals count everything; the lists only hold what the viewer may see, so
 * the usage of a credential never reveals resources of other teams.
 */
class CredentialUsageService extends Component
{
    private const WAITING_STATUSES = [Job::STATUS_PENDING, Job::STATUS_QUEUED, Job::STATUS_PENDING_APPROVAL];

    public function forCredential(Credential $credential, ?int $viewerId): CredentialUsage
    {
        $id = (int)$credential->id;
        $checker = $this->checker();

        $templates = $this->templateQuery($id);
        $visibleTemplates = $this->templateQuery($id)->with('project')->orderBy(['job_template.name' => SORT_ASC]);
        $templateFilter = $checker->buildChildResourceFilter($viewerId, 'job_template.project_id');
        if ($templateFilter !== null) {
            $visibleTemplates->andWhere($templateFilter);
        }

        $projects = Project::find()->andWhere(['scm_credential_id' => $id]);
        $visibleProjects = Project::find()->andWhere(['scm_credential_id' => $id])->orderBy(['name' => SORT_ASC]);
        $projectFilter = $checker->buildProjectFilter($viewerId);
        if ($projectFilter !== null) {
            $visibleProjects->andWhere($projectFilter);
        }

        /** @var list<JobTemplate> $templateRows */
        $templateRows = $visibleTemplates->all();
        /** @var list<Project> $projectRows */
        $projectRows = $visibleProjects->all();

        return new CredentialUsage(
            (string)$credential->name,
            array_map(static fn (JobTemplate $t): array => [
                'id' => (int)$t->id,
                'name' => (string)$t->name,
                'project_id' => (int)$t->project_id,
                'project_name' => $t->project->name ?? null,
                'role' => (int)$t->credential_id === $id ? Credential::ROLE_PRIMARY : Credential::ROLE_ADDITIONAL,
            ], $templateRows),
            (int)$templates->count(),
            array_map(static fn (Project $p): array => ['id' => (int)$p->id, 'name' => (string)$p->name], $projectRows),
            (int)$projects->count(),
            $this->waitingJobCount($id)
        );
    }

    /**
     * How many templates use the credential and already hold another vault
     * password. Turning the credential into a vault password would give
     * each of them a second one.
     */
    public function templatesWithAnotherVaultCount(Credential $credential): int
    {
        $id = (int)$credential->id;
        $count = 0;
        /** @var list<JobTemplate> $templates */
        $templates = $this->templateQuery($id)->with(['credential', 'jobTemplateCredentials.credential'])->all();
        foreach ($templates as $template) {
            foreach ($template->orderedCredentials() as $other) {
                if ((int)$other->id !== $id && $other->credential_type === Credential::TYPE_VAULT) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * Templates using the credential as primary or additional credential.
     */
    private function templateQuery(int $credentialId): ActiveQuery
    {
        $pivot = (new Query())->select('job_template_id')->from('{{%job_template_credential}}')->where(['credential_id' => $credentialId]);

        return JobTemplate::find()->andWhere(['or', ['job_template.credential_id' => $credentialId], ['job_template.id' => $pivot]]);
    }

    /**
     * Jobs that have not started and would need the credential. Checked in
     * PHP rather than with JSON SQL, which MySQL 5.7 and MariaDB disagree on.
     */
    private function waitingJobCount(int $credentialId): int
    {
        $count = 0;
        $rows = Job::find()->select(['id', 'runner_payload'])->where(['status' => self::WAITING_STATUSES])->asArray();
        foreach ($rows->each(200) as $row) {
            /** @var array{runner_payload: string|null} $row */
            $payload = json_decode((string)$row['runner_payload'], true);
            if (is_array($payload) && in_array($credentialId, JobCredentialResolver::credentialIds($payload), true)) {
                $count++;
            }
        }

        return $count;
    }

    private function checker(): ProjectAccessChecker
    {
        /** @var ProjectAccessChecker $checker */
        $checker = \Yii::$app->get('projectAccessChecker');

        return $checker;
    }
}
