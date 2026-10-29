<?php

declare(strict_types=1);

namespace app\controllers\api\v1;

use app\components\JobTemplateWarnings;
use app\components\SubmittedInteger;
use app\models\Credential;
use app\models\JobTemplate;
use app\controllers\api\v1\traits\ApiTeamScopingTrait;
use app\services\ApprovalRuleAssignmentPolicy;
use app\services\GatedJobTemplateLock;
use app\services\JobTemplateDeletionService;
use app\services\JobTemplateGate;
use app\services\JobTemplateRuleAssignment;
use yii\data\ActiveDataProvider;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;

/**
 * API v1: Job Templates
 *
 * GET    /api/v1/job-templates
 * GET    /api/v1/job-templates/{id}
 * POST   /api/v1/job-templates
 * PUT    /api/v1/job-templates/{id}
 * DELETE /api/v1/job-templates/{id}
 *
 * approval_rule_id is for admins (approval-rule.update): a create with a
 * rule, or an update that changes or removes it, answers 403 for everyone
 * else and writes nothing (JobTemplateRuleAssignment). Sending the stored
 * value again, or leaving the field out, is allowed.
 *
 * What a gated template (one with an approval rule of its own, or on an
 * inventory with one) runs is for admins too (GatedJobTemplateLock): for
 * everyone else an update that changes any field but name and description,
 * or credential_ids, answers 403 with GatedJobTemplateLock::CHANGE_REFUSED
 * (its own rule) or CHANGE_REFUSED_INVENTORY (its inventory's rule) and
 * writes nothing; so does giving a template an inventory with a rule, on a
 * create too (GATED_INVENTORY_REFUSED). A field sent with its stored value
 * is no change, so the body of a GET sent back with another name is a
 * rename. A rule change is refused first. An inventory the caller may not
 * see answers 422 "The selected inventory does not exist.", whether it has
 * a rule or not, and an own rule that differs from the inventory's answers
 * 422 (JobTemplateRuleAssignment::RULES_DIFFER).
 *
 * An integer field that is no whole number, such as 5.5, "abc", true or a
 * JSON number beyond the int range (1e30, 1e999), answers 422 with the
 * field's message ("Forks must be an integer.") and writes nothing
 * (applyIntFields()); on a gated template the lock's 403 comes first, for
 * the users it applies to, unless the lock sees no change: it compares a
 * field after the column's type cast, PHP's (int), so 5.5, "5abc" or " 5"
 * where 5 is stored, true where 1 is, and "abc", false or 0.5 where 0 is
 * are no change to it, and a body that changes nothing else it guards gets
 * the field's 422. A project that is no project ID answers 422 before the
 * lock and before the access checks of the project it names, which read
 * the project ID as the save stores it (refusesSubmittedProject()). A list
 * or an object in an integer field, also an empty one (a JSON [] or {}),
 * which the rules would take for empty, answers 422 with the field's
 * message too, before anything reads the body: on POST after the
 * permission check, on PUT after the 403 of the stored template's project,
 * for every caller before the access check of the project it names and the
 * lock (applyBodyUnlessRefused()). As approval_rule_id, it is a change of the
 * rule (403) for everyone but admins, and 422 "Approval rule must be an
 * integer." for them (ruleAssignmentError()).
 */
class JobTemplatesController extends BaseApiController
{
    use ApiTeamScopingTrait;

    /** The integer fields of a body, which applyIntFields() sets. */
    private const INTEGER_FIELDS = ['project_id', 'inventory_id', 'credential_id', 'verbosity', 'forks', 'timeout_minutes', 'runner_group_id'];

    protected function apiAccessRules(): array
    {
        return [
            'index' => 'job-template.view',
            'view' => 'job-template.view',
            'create' => 'job-template.create',
            'update' => 'job-template.update',
            'delete' => 'job-template.delete',
        ];
    }

    /**
     * Optional ?warning=<code> lists only templates with that warning
     * (see JobTemplateWarnings). The relations every item reads are loaded
     * for the page at once, the approval gate's (JobTemplateGate::RELATIONS)
     * included, so a page costs the same number of queries whatever its
     * templates and their inventories are gated by.
     *
     * @return array{data: array<int, mixed>, meta: array{total: int, page: int, per_page: int, pages: int}}|array{error: array{message: string}}
     */
    public function actionIndex(): array
    {
        $query = JobTemplate::find()
            ->with(array_merge(
                ['project', 'inventory', 'credential', 'jobTemplateCredentials.credential', 'vaultCheck.credential'],
                JobTemplateGate::RELATIONS
            ))
            ->orderBy(['job_template.id' => SORT_DESC]);
        $filter = $this->checker()->buildChildResourceFilter($this->currentUserId(), 'job_template.project_id');
        if ($filter !== null) {
            $query->andWhere($filter);
        }
        $warning = \Yii::$app->request->get('warning', '');
        if ($warning !== '' && (!is_string($warning) || !JobTemplateWarnings::filter($query, $warning))) {
            return $this->error('Unknown warning. Use one of: ' . implode(', ', JobTemplateWarnings::CODES) . '.', 422);
        }

        $dp = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 25],
        ]);
        $page = $this->requestedPage();

        /** @var JobTemplate[] $templates */
        $templates = $this->modelsOfPage($dp, $page);
        $changeable = $this->changeableIds(array_map(static fn (JobTemplate $t): int => (int)$t->id, $templates));

        return $this->paginated(
            array_map(fn (JobTemplate $t) => $this->serialize($t, isset($changeable[(int)$t->id])), $templates),
            (int)$dp->totalCount,
            $page,
            25
        );
    }

    /**
     * @return array{data: mixed}
     */
    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionView(int $id): array
    {
        $model = $this->findModel($id);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canViewChildResource($userId, $model->project_id)) {
            return $this->error('Forbidden.', 403);
        }
        return $this->success($this->serialize($model, $this->mayChange($model)));
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionCreate(): array
    {
        if (!$this->userCan('job-template.create')) {
            return $this->error('Forbidden.', 403);
        }

        $model = new JobTemplate();
        $body = (array)\Yii::$app->request->bodyParams;
        $model->created_by = (int)\Yii::$app->user->id;
        $bodyRefusal = $this->applyBodyUnlessRefused($model, $body);
        if ($bodyRefusal !== null) {
            return $this->error($bodyRefusal, 422);
        }
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canOperateChildResource($userId, $model->project_id)) {
            return $this->error('Forbidden.', 403);
        }
        $ruleAssignment = new JobTemplateRuleAssignment();
        $ruleError = $this->ruleAssignmentError($ruleAssignment, $model, $body, $userId, null);
        if ($ruleError !== null) {
            return $ruleError;
        }
        $this->restrictInventories($model, $userId);

        $credentialIds = $this->readCredentialIds($body);
        if ($credentialIds === false) {
            return $this->error('credential_ids must be an array of credential IDs.', 422);
        }
        $audit = ['source' => 'api'] + $ruleAssignment->auditContext($model, null);
        if (!$this->credentialService()->saveWithCredentials($model, $credentialIds, $audit, $userId)) {
            return $this->saveError($model);
        }

        return $this->success($this->serialize($model, $this->mayChange($model)), 201);
    }

    /**
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionUpdate(int $id): array
    {
        if (!$this->userCan('job-template.update')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canOperateChildResource($userId, $model->project_id)) {
            return $this->error('Forbidden.', 403);
        }
        $body = (array)\Yii::$app->request->bodyParams;
        $bodyRefusal = $this->applyBodyUnlessRefused($model, $body);
        if ($bodyRefusal !== null) {
            return $this->error($bodyRefusal, 422);
        }
        // The project it moves to as well, not only the one it comes from.
        if (!$this->checker()->canOperateChildResource($userId, $model->project_id)) {
            return $this->error('Forbidden.', 403);
        }
        $storedRule = ApprovalRuleAssignmentPolicy::storedRuleId($model);
        $ruleAssignment = new JobTemplateRuleAssignment();
        $ruleError = $this->ruleAssignmentError($ruleAssignment, $model, $body, $userId, $storedRule);
        if ($ruleError !== null) {
            return $ruleError;
        }
        $this->restrictInventories($model, $userId);

        $credentialIds = $this->readCredentialIds($body);
        if ($credentialIds === false) {
            return $this->error('credential_ids must be an array of credential IDs.', 422);
        }
        $audit = ['source' => 'api'] + $ruleAssignment->auditContext($model, $storedRule);
        if (!$this->credentialService()->saveWithCredentials($model, $credentialIds, $audit, $userId)) {
            return $this->saveError($model);
        }

        return $this->success($this->serialize($model, $this->mayChange($model)));
    }

    /**
     * A soft delete, like the web UI: jobs keep their template link. The
     * template's enabled schedules are disabled and its waiting jobs
     * canceled (JobTemplateDeletionService); the response counts both.
     *
     * @return array{data: mixed}|array{error: array{message: string}}
     */
    public function actionDelete(int $id): array
    {
        if (!$this->userCan('job-template.delete')) {
            return $this->error('Forbidden.', 403);
        }

        $model = $this->findModel($id);
        $userId = $this->currentUserId();
        if ($userId === null || !$this->checker()->canOperateChildResource($userId, $model->project_id)) {
            return $this->error('Forbidden.', 403);
        }
        /** @var JobTemplateDeletionService $deletion */
        $deletion = \Yii::$app->get('jobTemplateDeletionService');
        $result = $deletion->delete($model, $userId, JobTemplateDeletionService::SOURCE_API);
        if (!$result['deleted']) {
            // Another request deleted it first.
            throw new NotFoundHttpException("Template #{$id} not found.");
        }

        return $this->success($result);
    }

    /**
     * Applies $body to $model (applyBody()) unless the body is refused: the
     * refusal (422), else null. Refused are, before anything reads them, a
     * list or an object in an integer field, with the field's message
     * ("Verbosity must be an integer.", SubmittedInteger::refusesLists()):
     * the rules would take an empty one for empty, and the column store it
     * as 0 (verbosity 0 was saved), or its foreign key refuse 0; then a
     * project that is no project ID (refusesSubmittedProject()), before the
     * access checks, which read the project ID as the save stores it.
     *
     * @phpstan-impure it changes $model
     * @param array<string, mixed> $body
     */
    private function applyBodyUnlessRefused(JobTemplate $model, array $body): ?string
    {
        if (SubmittedInteger::refusesLists($model, $body, self::INTEGER_FIELDS)) {
            return $this->firstError($model);
        }
        $this->applyBody($model, $body);

        return $this->refusesSubmittedProject($model) ? $this->firstError($model) : null;
    }

    /**
     * Applies the approval rule $body submits ($assignment->apply()): 403
     * with the refusal for a caller who may not change the rule, a list
     * among the values they may not send; for an admin, 422 for a list or an
     * object, also an empty one, which the integer rule would take for empty
     * and the foreign key refuse as rule 0, a 500
     * (SubmittedInteger::refusesLists()); null when the rule was applied or
     * the body sends none.
     *
     * @param array<string, mixed> $body
     * @return array{error: array{message: string}}|null
     */
    private function ruleAssignmentError(
        JobTemplateRuleAssignment $assignment,
        JobTemplate $model,
        array $body,
        int $userId,
        ?int $baseline
    ): ?array {
        $refusal = $assignment->apply($model, $body, $userId, $baseline);
        if ($refusal !== null) {
            return $this->error($refusal, 403);
        }
        if (SubmittedInteger::refusesLists($model, $body, [JobTemplateRuleAssignment::FIELD])) {
            return $this->error($this->firstError($model), 422);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBody(JobTemplate $model, array $body): void
    {
        $this->applyStringFields($model, $body);
        $this->applyIntFields($model, $body);
        if (array_key_exists('become', $body)) {
            $model->become = (bool)$body['become'];
        }
        if (array_key_exists('survey_fields', $body)) {
            $model->survey_fields = $body['survey_fields'] !== null
                ? (is_string($body['survey_fields']) ? $body['survey_fields'] : (string)json_encode($body['survey_fields']))
                : null;
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyStringFields(JobTemplate $model, array $body): void
    {
        $fields = ['name', 'description', 'playbook', 'become_method', 'become_user', 'limit', 'tags', 'skip_tags', 'extra_vars'];
        $nullable = ['description', 'limit', 'tags', 'skip_tags', 'extra_vars'];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            if ($body[$field] === null && in_array($field, $nullable, true)) {
                $model->$field = null;
            } else {
                $model->$field = (string)$body[$field];
            }
        }
    }

    /**
     * approval_rule_id is not among them: only JobTemplateRuleAssignment
     * sets it, after its check.
     *
     * A whole number is set as its int (SubmittedInteger: 5, "5", "+5" and
     * 5.0 are 5); any other value as sent, for the field's integer rule to
     * refuse with 422, a JSON number beyond the int range (1e30, 1e999)
     * among them, whose (int) cast warns since PHP 8.5: the request answered
     * 500. null clears credential_id and runner_group_id; an empty value
     * (null or "") is 0 otherwise, as the cast made it: the integer rule
     * skips empty values, and verbosity, forks and timeout_minutes hold no
     * NULL. A list or an object never gets here (applyBodyUnlessRefused()).
     *
     * @param array<string, mixed> $body
     */
    private function applyIntFields(JobTemplate $model, array $body): void
    {
        $nullable = ['credential_id', 'runner_group_id'];
        foreach (self::INTEGER_FIELDS as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            if ($value === null && in_array($field, $nullable, true)) {
                $model->$field = null;
            } elseif (in_array($value, [null, ''], true)) {
                $model->$field = 0;
            } else {
                // Perhaps no int: assigned as load() assigns, for the rules to check.
                $model->setAttribute($field, SubmittedInteger::orAsSubmitted($value));
            }
        }
    }

    /**
     * Only inventories the user may see, so a request cannot point the
     * template at another team's hosts.
     */
    private function restrictInventories(JobTemplate $model, int $userId): void
    {
        $model->restrictInventories($this->checker()->buildChildResourceFilter($userId, 'inventory.project_id'));
    }

    /**
     * $withTrigger adds has_trigger_token and trigger_user_id, the user an
     * inbound trigger launches as (who generated the token, or created_by
     * for older tokens; null without a token). Only callers who may change
     * the template get them, as only they see the trigger card on its page.
     * The token and its hash are never returned.
     *
     * For every caller who may view the template: approval_rule_id and
     * approval_rule_name name the template's own approval rule (null for
     * none), which only admins assign. approval_gate, read-only, names the
     * rule its launches wait for, its own or else its inventory's
     * (JobTemplateGate::toApi()): the rule's ID and name (null once its row
     * is gone) and what requires it (required_by: job_template or
     * inventory); null when nothing gates the template. So a template gated
     * only through its inventory has approval_rule_id null and an
     * approval_gate whose required_by is inventory.
     *
     * @return array{id: int, name: string, description: string|null, project_id: int, project_name: string|null, inventory_id: int, inventory_name: string|null, runner_group_id: int|null, credential_id: int|null, credential_ids: list<int>, credentials: list<array{id: int, name: string, credential_type: string, role: string}>, approval_rule_id: int|null, approval_rule_name: string|null, approval_gate: array{approval_rule_id: int, approval_rule_name: string|null, required_by: string}|null, playbook: string, verbosity: int, forks: int, become: bool, become_method: string, become_user: string, limit: string|null, tags: string|null, skip_tags: string|null, has_survey: bool, warnings: list<array{code: string, message: string, credential_ids: list<int>}>, created_by: int, created_at: int, updated_at: int, has_trigger_token?: bool, trigger_user_id?: int|null}
     */
    private function serialize(JobTemplate $t, bool $withTrigger): array
    {
        $credentials = $this->credentialService()->describe($t);
        $additional = array_filter($credentials, static fn (array $c): bool => $c['role'] === Credential::ROLE_ADDITIONAL);
        $rule = $t->approval_rule_id === null ? null : $t->approvalRule;

        $data = [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'project_id' => $t->project_id,
            'project_name' => $t->project->name ?? null,
            'inventory_id' => $t->inventory_id,
            'inventory_name' => $t->inventory->name ?? null,
            'runner_group_id' => $t->runner_group_id,
            'credential_id' => $t->credential_id,
            'credential_ids' => array_values(array_column($additional, 'id')),
            'credentials' => $credentials,
            'approval_rule_id' => $t->approval_rule_id === null ? null : (int)$t->approval_rule_id,
            'approval_rule_name' => $rule?->name,
            'approval_gate' => JobTemplateGate::of($t)->toApi(),
            'playbook' => $t->playbook,
            'verbosity' => $t->verbosity,
            'forks' => $t->forks,
            'become' => (bool)$t->become,
            'become_method' => $t->become_method,
            'become_user' => $t->become_user,
            'limit' => $t->limit,
            'tags' => $t->tags,
            'skip_tags' => $t->skip_tags,
            'has_survey' => $t->hasSurvey(),
            'warnings' => JobTemplateWarnings::forTemplate($t),
            'created_by' => (int)$t->created_by,
            'created_at' => $t->created_at,
            'updated_at' => $t->updated_at,
        ];
        if ($withTrigger) {
            $data['has_trigger_token'] = $t->hasTriggerToken();
            $data['trigger_user_id'] = $t->hasTriggerToken() ? $t->getTriggerUserId() : null;
        }

        return $data;
    }

    /**
     * Whether the caller may change the template.
     */
    private function mayChange(JobTemplate $t): bool
    {
        return isset($this->changeableIds([(int)$t->id])[(int)$t->id]);
    }

    /**
     * The IDs of the given job templates the caller may change:
     * job-template.update and operator access to the template's project, as
     * an update or the template's trigger token needs. Decided for all of
     * them at once, so a list costs no query per template.
     *
     * @param list<int> $templateIds
     * @return array<int, true>
     */
    private function changeableIds(array $templateIds): array
    {
        if (!$this->userCan('job-template.update')) {
            return [];
        }
        $filter = $this->checker()->buildChildOperateFilter($this->currentUserId(), 'job_template.project_id');
        if ($filter !== null) {
            $templateIds = array_map('intval', JobTemplate::find()
                ->select('job_template.id')
                ->andWhere(['job_template.id' => $templateIds])
                ->andWhere($filter)
                ->column());
        }

        return array_fill_keys($templateIds, true);
    }

    /**
     * The additional credentials from the request body: null when the key is
     * absent (keep the current ones), false when it is not a list.
     *
     * @param array<string, mixed> $body
     * @return list<mixed>|null|false
     */
    private function readCredentialIds(array $body): array|null|false
    {
        if (!array_key_exists('credential_ids', $body)) {
            return null;
        }
        $ids = $body['credential_ids'] ?? [];

        return is_array($ids) && array_is_list($ids) ? $ids : false;
    }

    private function credentialService(): \app\services\JobTemplateCredentialService
    {
        /** @var \app\services\JobTemplateCredentialService $service */
        $service = \Yii::$app->get('jobTemplateCredentialService');

        return $service;
    }

    private function findModel(int $id): JobTemplate
    {
        /** @var JobTemplate|null $t */
        $t = JobTemplate::findOne($id);
        if ($t === null) {
            throw new NotFoundHttpException("Template #{$id} not found.");
        }
        return $t;
    }

    /**
     * The answer to a save that wrote nothing: 403 with the reason when
     * GatedJobTemplateLock refused it (refusalOf()), else 422 with the first
     * validation error.
     *
     * @return array{error: array{message: string}}
     */
    private function saveError(JobTemplate $model): array
    {
        return GatedJobTemplateLock::refused($model)
            ? $this->error((string)GatedJobTemplateLock::refusalOf($model), 403)
            : $this->error($this->firstError($model), 422);
    }

    /**
     * @param ActiveRecord $model
     */
    private function firstError($model): string
    {
        foreach ($model->errors as $errors) {
            return $errors[0] ?? 'Validation failed.';
        }
        return 'Validation failed.';
    }
}
