<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\components\ApiBodyValues;
use app\components\SurveySecrets;
use app\controllers\api\v1\NotificationTemplatesController;
use app\models\ApiToken;
use app\models\AuditLog;
use app\models\JobTemplate;
use app\models\NotificationTemplate;
use app\models\User;
use app\models\WorkflowTemplate;
use app\services\notification\NotificationTemplateBody;
use app\services\notification\NotificationTemplateSave;
use app\tests\integration\controllers\ChannelSecretFixtures;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\TeamScopeFixtures;
use yii\log\Logger;

/**
 * The options and the identity of a notification template in the REST API
 * (GET, POST and PUT /api/v1/notification-templates): its level, its
 * workflow scope and workflow filter (workflow_template_ids, of which every
 * caller reads only the workflow templates they may see, with
 * workflow_templates_hidden counting the others), its events as a list or
 * as comma-separated text, and whom it delivers as (delivers_as,
 * delivers_as_inactive), which no body sets.
 *
 * A PUT replaces only the part of the filter that its caller may see: the
 * workflow templates of another team stay, and an ID the caller may not
 * see is refused with 422, with nothing written. workflow_template_ids is
 * read as the integer rule reads an ID (SubmittedId): a fraction, a number
 * beyond the int range, true, a list and text that is no ID are refused.
 */
class NotificationTemplatesApiTest extends WebControllerTestCase
{
    use TeamScopeFixtures;
    use OpenApiSchemaAssertions;

    /**
     * The template answers every field of the spec's NotificationTemplate,
     * of its documented type: level all and workflow scope all by default,
     * an empty filter, its events as a list and the creator as the user it
     * delivers as.
     */
    public function testATemplateAnswersItsOptionsAndWhomItDeliversAs(): void
    {
        $admin = $this->createUserWithRole('nt-api-fields', 'admin');
        $template = $this->createNotificationTemplate((int)$admin->id, NotificationTemplate::CHANNEL_EMAIL, 'job.failed,workflow.waiting');
        $this->authenticate($admin);

        $data = $this->dataOf($this->call('view', ['id' => $template->id]));

        $this->assertMatchesSchema('NotificationTemplate', $data);
        $this->assertSame(['job.failed', 'workflow.waiting'], $data['events']);
        $this->assertSame(NotificationTemplate::LEVEL_ALL, $data['level']);
        $this->assertSame(NotificationTemplate::WORKFLOW_SCOPE_ALL, $data['workflow_scope']);
        $this->assertSame([], $data['workflow_template_ids']);
        $this->assertSame(0, $data['workflow_templates_hidden']);
        $this->assertSame((int)$admin->id, $data['created_by']);
        $this->assertSame((int)$admin->id, $data['delivers_as']);
        $this->assertFalse($data['delivers_as_inactive']);
    }

    /**
     * A create takes the level, the workflow scope, the workflow filter and
     * the events as a list, and answers them; it delivers as its caller.
     */
    public function testACreateTakesTheOptionsAndAnswersThem(): void
    {
        $operator = $this->createUserWithRole('nt-api-create-options', 'operator');
        $first = $this->createWorkflowTemplate((int)$operator->id);
        $second = $this->createWorkflowTemplate((int)$operator->id);
        $this->authenticate($operator);
        $this->setBody($this->emailBody([
            'events' => ['workflow.failed', 'workflow.waiting'],
            'level' => NotificationTemplate::LEVEL_ESSENTIAL,
            'workflow_scope' => NotificationTemplate::WORKFLOW_SCOPE_SELECTED,
            'workflow_template_ids' => [(int)$second->id, (int)$first->id, (int)$second->id],
        ]));

        $answer = $this->call('create');

        $this->assertSame(201, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $data = $this->dataOf($answer);
        $this->assertMatchesSchema('NotificationTemplate', $data);
        $ids = $this->sorted([(int)$first->id, (int)$second->id]);
        $this->assertSame($ids, $data['workflow_template_ids'], 'ascending, each once');
        $this->assertSame(0, $data['workflow_templates_hidden']);
        $this->assertSame(['workflow.failed', 'workflow.waiting'], $data['events']);
        $this->assertSame(NotificationTemplate::LEVEL_ESSENTIAL, $data['level']);
        $this->assertSame(NotificationTemplate::WORKFLOW_SCOPE_SELECTED, $data['workflow_scope']);
        $this->assertSame((int)$operator->id, $data['delivers_as']);
        $stored = $this->reload((int)$data['id']);
        $this->assertSame('workflow.failed,workflow.waiting', $stored->events);
        $this->assertSame($ids, $stored->getWorkflowTemplateIds());
        $this->assertCount(1, $this->auditEntries(AuditLog::ACTION_NOTIFICATION_TEMPLATE_CREATED, (int)$data['id']));
    }

    /**
     * @return array<string, array{0: mixed, 1: string, 2: list<string>}>
     */
    public static function eventsProvider(): array
    {
        return [
            'comma-separated text' => ['job.failed, workflow.failed', 'job.failed, workflow.failed', ['job.failed', 'workflow.failed']],
            'a list' => [['job.failed', 'workflow.failed'], 'job.failed,workflow.failed', ['job.failed', 'workflow.failed']],
            'a list with an empty name' => [['job.failed', ''], 'job.failed,', ['job.failed']],
        ];
    }

    /**
     * @dataProvider eventsProvider
     * @param list<string> $answered
     */
    public function testEventsAreAListOrCommaSeparatedText(mixed $events, string $stored, array $answered): void
    {
        $operator = $this->createUserWithRole('nt-api-events', 'operator');
        $template = $this->createNotificationTemplate((int)$operator->id);
        $this->authenticate($operator);
        $this->setBody(['events' => $events]);

        $answer = $this->call('update', ['id' => $template->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame($stored, $this->reload((int)$template->id)->events);
        $this->assertSame($answered, $this->dataOf($answer)['events']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedEventsProvider(): array
    {
        return [
            'an object' => [['first' => 'job.failed']],
            'a list with a number' => [['job.failed', 5]],
            'a list with a list' => [[['job.failed']]],
            'a list with null' => [['job.failed', null]],
            'a list with true' => [['job.failed', true]],
        ];
    }

    /**
     * Events that are neither text nor a list of names are refused, and
     * nothing is written: on create no template, on update no change.
     *
     * @dataProvider malformedEventsProvider
     */
    public function testEventsOfAnotherFormAreRefused(mixed $events): void
    {
        $operator = $this->createUserWithRole('nt-api-events-form', 'operator');
        $template = $this->createNotificationTemplate((int)$operator->id);
        $this->authenticate($operator);
        $name = 'nt-api-events-form-' . uniqid('', true);

        $this->setBody($this->emailBody(['name' => $name, 'events' => $events]));
        $created = $this->call('create');
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => NotificationTemplateBody::EVENTS_FORM]], $created);
        $this->assertNull(NotificationTemplate::findOne(['name' => $name]));

        $this->setBody(['events' => $events, 'name' => $name]);
        $updated = $this->call('update', ['id' => $template->id]);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => NotificationTemplateBody::EVENTS_FORM]], $updated);
        $this->assertSame('job.failed', $this->reload((int)$template->id)->events);
        $this->assertSame($template->name, $this->reload((int)$template->id)->name);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function textFieldProvider(): array
    {
        $fields = [];
        foreach (['name', 'description', 'channel', 'subject_template', 'body_template', 'level', 'workflow_scope'] as $field) {
            $fields[$field] = [$field];
        }

        return $fields;
    }

    /**
     * Regression: a list or an object for a text field of the body, such as
     * {"name": ["ops"]}, reached the string cast of the body's reader, whose
     * "Array to string conversion" warning the error handler turned into an
     * exception: the API answered 500. It answers 422 now, and writes
     * nothing.
     *
     * @dataProvider textFieldProvider
     */
    public function testRegressionAListForATextFieldWasAServerError(string $field): void
    {
        $operator = $this->createUserWithRole('nt-api-list-field', 'operator');
        $template = $this->createNotificationTemplate((int)$operator->id);
        $before = $this->reload((int)$template->id)->getAttributes();
        $this->authenticate($operator);
        $refusal = ['error' => ['message' => sprintf(ApiBodyValues::NOT_A_VALUE, $field)]];

        foreach ([['ops'], ['key' => 'ops'], []] as $value) {
            $name = 'nt-api-list-field-' . uniqid('', true);
            $this->setBody(array_replace($this->emailBody(['name' => $name]), [$field => $value]));
            $this->assertSame($refusal, $this->call('create'), (string)json_encode($value));
            $this->assertSame(422, \Yii::$app->response->statusCode);
            $this->assertNull(NotificationTemplate::findOne(['name' => $name]));

            $this->setBody([$field => $value]);
            $this->assertSame($refusal, $this->call('update', ['id' => $template->id]));
            $this->assertSame(422, \Yii::$app->response->statusCode);
        }
        $this->assertSame($before, $this->reload((int)$template->id)->getAttributes());
    }

    /**
     * null empties a text field that may be empty, and is no value of one
     * that may not: the template's name is required.
     */
    public function testNullEmptiesTheTextFieldsThatMayBeEmpty(): void
    {
        $operator = $this->createUserWithRole('nt-api-null', 'operator');
        $template = $this->createNotificationTemplate((int)$operator->id);
        $this->authenticate($operator);

        $this->setBody(['description' => null, 'subject_template' => null, 'body_template' => null]);
        $answer = $this->dataOf($this->call('update', ['id' => $template->id]));

        $stored = $this->reload((int)$template->id);
        $this->assertNull($stored->description);
        $this->assertNull($stored->subject_template);
        $this->assertNull($stored->body_template);
        $this->assertNull($answer['body_template']);

        $this->setBody(['name' => null]);
        $this->assertSame(['error' => ['message' => 'Name cannot be blank.']], $this->call('update', ['id' => $template->id]));
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame($template->name, $this->reload((int)$template->id)->name);
    }

    /**
     * A page past the last of the list answers no templates, and reads no
     * filter of none.
     */
    public function testAPagePastTheLastAnswersNoTemplates(): void
    {
        $admin = $this->createUserWithRole('nt-api-past', 'admin');
        $this->createNotificationTemplate((int)$admin->id);
        $this->authenticate($admin);
        $this->setQueryParams(['page' => '987654']);

        $statements = $this->statementsOf(function () use (&$answer): void {
            $answer = $this->call('index');
        });

        $this->assertIsArray($answer);
        $this->assertSame([], $answer['data'] ?? null);
        $this->assertSame([], preg_grep('/FROM `notification_template_workflow_template`/', $statements) ?: []);
    }

    /**
     * A level or a workflow scope the model does not know is refused.
     */
    public function testAnUnknownLevelOrScopeIsRefused(): void
    {
        $operator = $this->createUserWithRole('nt-api-level', 'operator');
        $template = $this->createNotificationTemplate((int)$operator->id);
        $this->authenticate($operator);

        $this->setBody(['level' => 'loud']);
        $this->assertSame(['error' => ['message' => 'Level is invalid.']], $this->call('update', ['id' => $template->id]));
        $this->assertSame(422, \Yii::$app->response->statusCode);

        $this->setBody(['workflow_scope' => 'some']);
        $this->assertSame(['error' => ['message' => 'Workflow Scope is invalid.']], $this->call('update', ['id' => $template->id]));
        $this->assertSame(422, \Yii::$app->response->statusCode);

        $stored = $this->reload((int)$template->id);
        $this->assertSame(NotificationTemplate::LEVEL_ALL, $stored->level);
        $this->assertSame(NotificationTemplate::WORKFLOW_SCOPE_ALL, $stored->workflow_scope);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedIdsProvider(): array
    {
        return [
            'text' => ['5'],
            'a number' => [5],
            'null' => [null],
            'true' => [true],
            'an object' => [['first' => 5]],
            'a list of lists' => [[[5]]],
            'a list with true' => [[true]],
            'a list with a fraction' => [[12.5]],
            'a list with a fraction that reads as a whole number' => [[2.9999999999999996]],
            'a list with a number beyond the int range' => [[1e30]],
            'a list with text' => [['abc']],
            'a list with text after digits' => [['5abc']],
            'a list with an empty text' => [['']],
            'a list with null' => [[null]],
            'a list with zero' => [[0]],
            'a list with a negative ID' => [[-3]],
        ];
    }

    /**
     * workflow_template_ids is a list of IDs, each read as the integer rule
     * reads it (SubmittedId): anything else is refused with 422, on create
     * and on update, and nothing is written.
     *
     * @dataProvider malformedIdsProvider
     */
    public function testWorkflowTemplateIdsMustBeAListOfIds(mixed $ids): void
    {
        $operator = $this->createUserWithRole('nt-api-ids-form', 'operator');
        $workflow = $this->createWorkflowTemplate((int)$operator->id);
        $template = $this->createNotificationTemplate((int)$operator->id);
        $this->assertSame([], $template->setWorkflowTemplateIds([(int)$workflow->id], (int)$operator->id));
        $this->authenticate($operator);
        $refusal = ['error' => ['message' => NotificationTemplateBody::WORKFLOW_TEMPLATE_IDS_FORM]];
        $name = 'nt-api-ids-form-' . uniqid('', true);

        $this->setBody($this->emailBody(['name' => $name, 'workflow_template_ids' => $ids]));
        $this->assertSame($refusal, $this->call('create'));
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertNull(NotificationTemplate::findOne(['name' => $name]));

        $this->setBody(['name' => $name, 'workflow_template_ids' => $ids]);
        $this->assertSame($refusal, $this->call('update', ['id' => $template->id]));
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $stored = $this->reload((int)$template->id);
        $this->assertSame([(int)$workflow->id], $stored->getWorkflowTemplateIds());
        $this->assertSame($template->name, $stored->name);
        $this->assertSame([], $this->auditEntries(AuditLog::ACTION_NOTIFICATION_TEMPLATE_UPDATED, (int)$template->id));
    }

    /**
     * An ID as the integer rule reads it is taken: its digits as text, and
     * a number without a fraction. An empty list empties the filter.
     */
    public function testAnIdIsReadAsTheIntegerRuleReadsIt(): void
    {
        $operator = $this->createUserWithRole('nt-api-ids-read', 'operator');
        $first = $this->createWorkflowTemplate((int)$operator->id);
        $second = $this->createWorkflowTemplate((int)$operator->id);
        $template = $this->createNotificationTemplate((int)$operator->id);
        $this->authenticate($operator);

        $this->setBody(['workflow_template_ids' => [(string)$first->id, (float)$second->id]]);
        $answer = $this->call('update', ['id' => $template->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame($this->sorted([(int)$first->id, (int)$second->id]), $this->reload((int)$template->id)->getWorkflowTemplateIds());

        $this->setBody(['workflow_template_ids' => []]);
        $this->call('update', ['id' => $template->id]);
        $this->assertSame([], $this->reload((int)$template->id)->getWorkflowTemplateIds());
    }

    /**
     * Regression guard (spec answer 5): a viewer of team B reads only the
     * workflow templates of the filter that they may see; those of team A
     * count in workflow_templates_hidden, in the detail and in the list.
     * An admin sees every live one.
     */
    public function testAViewerOfTeamBDoesNotSeeTheWorkflowsOfTeamA(): void
    {
        $world = $this->teams();
        $template = $this->filteredTemplate($world, [$world['teamA'], $world['teamB'], $world['open']]);

        $this->authenticate($world['viewerB']);
        $detail = $this->dataOf($this->call('view', ['id' => $template->id]));
        $listed = $this->listed($this->call('index'), (int)$template->id);

        $visible = $this->sorted([(int)$world['teamB']->id, (int)$world['open']->id]);
        foreach (['detail' => $detail, 'list' => $listed] as $where => $data) {
            $this->assertSame($visible, $data['workflow_template_ids'], $where);
            $this->assertSame(1, $data['workflow_templates_hidden'], $where);
        }
        $this->assertStringNotContainsString('"' . $world['teamA']->name . '"', (string)json_encode($detail));

        $this->authenticate($world['admin']);
        $all = $this->dataOf($this->call('view', ['id' => $template->id]));
        $this->assertSame($this->sorted([(int)$world['teamA']->id, (int)$world['teamB']->id, (int)$world['open']->id]), $all['workflow_template_ids']);
        $this->assertSame(0, $all['workflow_templates_hidden']);
    }

    /**
     * Spec answer 5: a PUT replaces the part of the filter its caller may
     * see; the workflow templates of another team stay as they are, and the
     * answer counts them as hidden.
     */
    public function testAPutKeepsTheWorkflowsItsCallerCannotSee(): void
    {
        $world = $this->teams();
        $template = $this->filteredTemplate($world, [$world['teamA'], $world['teamB']]);
        $this->authenticate($world['editorA']);

        $this->setBody(['workflow_template_ids' => [(int)$world['open']->id]]);
        $answer = $this->call('update', ['id' => $template->id]);

        $this->assertSame(200, \Yii::$app->response->statusCode, (string)json_encode($answer));
        $this->assertSame([(int)$world['open']->id], $this->dataOf($answer)['workflow_template_ids']);
        $this->assertSame(1, $this->dataOf($answer)['workflow_templates_hidden']);
        $this->assertSame($this->sorted([(int)$world['teamB']->id, (int)$world['open']->id]), $this->reload((int)$template->id)->getWorkflowTemplateIds());

        $this->setBody(['workflow_template_ids' => []]);
        $emptied = $this->dataOf($this->call('update', ['id' => $template->id]));
        $this->assertSame([], $emptied['workflow_template_ids']);
        $this->assertSame(1, $emptied['workflow_templates_hidden']);
        $this->assertSame([(int)$world['teamB']->id], $this->reload((int)$template->id)->getWorkflowTemplateIds());

        $this->setBody(['name' => 'nt-api-renamed-' . uniqid('', true)]);
        $this->call('update', ['id' => $template->id]);
        $this->assertSame([(int)$world['teamB']->id], $this->reload((int)$template->id)->getWorkflowTemplateIds(), 'a PUT without the field keeps the filter');
    }

    /**
     * A workflow template the caller may not see (another team's, deleted,
     * or none at all) is refused, as if it did not exist, and the whole
     * request writes nothing: no change of the filter or of the other
     * fields, no change of whom it delivers as and no audit entry; a create
     * leaves no template behind.
     */
    public function testAnIdTheCallerCannotSeeIsRefusedAndNothingIsWritten(): void
    {
        $world = $this->teams();
        $deleted = $this->createWorkflowWithJobSteps((int)$world['admin']->id, (int)$world['scope']['foreign']->id);
        $deleted->softDelete();
        $template = $this->filteredTemplate($world, [$world['teamA']]);
        $this->authenticate($world['editorA']);
        $before = $this->reload((int)$template->id)->getAttributes();

        foreach ([[(int)$world['teamB']->id], [(int)$deleted->id], [(int)$world['open']->id, 987654321]] as $ids) {
            $refused = array_values(array_diff($ids, [(int)$world['open']->id]));
            $this->setBody(['name' => 'nt-api-refused', 'level' => NotificationTemplate::LEVEL_ESSENTIAL, 'workflow_template_ids' => $ids]);
            $answer = $this->call('update', ['id' => $template->id]);

            $this->assertSame(422, \Yii::$app->response->statusCode);
            $this->assertSame(['error' => ['message' => sprintf(NotificationTemplateSave::WORKFLOW_TEMPLATES_NOT_FOUND, '#' . implode(', #', $refused))]], $answer);
            $this->assertSame($before, $this->reload((int)$template->id)->getAttributes());
            $this->assertSame([(int)$world['teamA']->id], $this->reload((int)$template->id)->getWorkflowTemplateIds());
        }
        $this->assertSame([], $this->auditEntries(AuditLog::ACTION_NOTIFICATION_TEMPLATE_UPDATED, (int)$template->id));

        $name = 'nt-api-refused-create-' . uniqid('', true);
        $this->setBody($this->emailBody(['name' => $name, 'workflow_template_ids' => [(int)$world['teamB']->id]]));
        $created = $this->call('create');
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => sprintf(NotificationTemplateSave::WORKFLOW_TEMPLATES_NOT_FOUND, '#' . $world['teamB']->id)]], $created);
        $this->assertNull(NotificationTemplate::findOne(['name' => $name]));
        $this->assertFalse(AuditLog::find()->where(['action' => AuditLog::ACTION_NOTIFICATION_TEMPLATE_CREATED])->andWhere(['like', 'metadata', $name])->exists());
    }

    /**
     * A workflow template of the filter that was deleted stays in it,
     * hidden from everyone, also from an admin's PUT.
     */
    public function testADeletedWorkflowTemplateStaysInTheFilterHidden(): void
    {
        $admin = $this->createUserWithRole('nt-api-deleted-wf', 'admin');
        $kept = $this->createWorkflowTemplate((int)$admin->id);
        $deleted = $this->createWorkflowTemplate((int)$admin->id);
        $template = $this->createNotificationTemplate((int)$admin->id);
        $this->assertSame([], $template->setWorkflowTemplateIds([(int)$kept->id, (int)$deleted->id], (int)$admin->id));
        $deleted->softDelete();
        $this->authenticate($admin);

        $data = $this->dataOf($this->call('view', ['id' => $template->id]));
        $this->assertSame([(int)$kept->id], $data['workflow_template_ids']);
        $this->assertSame(1, $data['workflow_templates_hidden']);

        $this->setBody(['workflow_template_ids' => []]);
        $this->call('update', ['id' => $template->id]);
        $this->assertSame([(int)$deleted->id], $this->reload((int)$template->id)->getWorkflowTemplateIds());
    }

    /**
     * A change of the level, the workflow scope or the filter is a change
     * of what the template delivers: the caller becomes the user it
     * delivers as (audited as delivers_as {from, to}). A PUT that sends the
     * filter as the caller reads it changes nothing of it.
     */
    public function testChangingTheOptionsMakesTheCallerTheUserItDeliversAs(): void
    {
        $world = $this->teams();
        $template = $this->filteredTemplate($world, [$world['teamA'], $world['open']]);
        $editor = $world['editorA'];
        $this->authenticate($editor);
        $shown = $this->dataOf($this->call('view', ['id' => $template->id]))['workflow_template_ids'];

        $this->setBody(['name' => 'nt-api-round-trip', 'workflow_template_ids' => $shown]);
        $this->call('update', ['id' => $template->id]);
        $this->assertSame((int)$world['admin']->id, (int)$this->reload((int)$template->id)->delivers_as, 'the filter as read is no change');

        $changes = [
            ['level' => NotificationTemplate::LEVEL_ESSENTIAL],
            ['workflow_scope' => NotificationTemplate::WORKFLOW_SCOPE_ALL],
            ['workflow_template_ids' => [(int)$world['open']->id]],
        ];
        foreach ($changes as $change) {
            NotificationTemplate::updateAll(['delivers_as' => (int)$world['admin']->id], ['id' => $template->id]);
            $before = count($this->auditEntries(AuditLog::ACTION_NOTIFICATION_TEMPLATE_UPDATED, (int)$template->id));
            $this->setBody($change);

            $answer = $this->dataOf($this->call('update', ['id' => $template->id]));

            $this->assertSame((int)$editor->id, $answer['delivers_as'], (string)json_encode($change));
            $entries = array_slice($this->auditEntries(AuditLog::ACTION_NOTIFICATION_TEMPLATE_UPDATED, (int)$template->id), $before);
            $this->assertCount(1, $entries);
            $this->assertSame(['from' => (int)$world['admin']->id, 'to' => (int)$editor->id], $entries[0]['delivers_as'] ?? null);
        }
    }

    /**
     * A template whose user is no longer active delivers nothing, and says
     * so: delivers_as_inactive in the detail and in the list.
     */
    public function testATemplateThatDeliversAsAnInactiveUserSaysSo(): void
    {
        $admin = $this->createUserWithRole('nt-api-inactive-reader', 'admin');
        $gone = $this->createUserWithRole('nt-api-inactive', 'operator');
        $active = $this->createNotificationTemplate((int)$admin->id);
        $inactive = $this->createNotificationTemplate((int)$gone->id);
        $gone->status = User::STATUS_INACTIVE;
        $this->assertTrue($gone->save(false));
        $this->authenticate($admin);

        $this->assertTrue($this->dataOf($this->call('view', ['id' => $inactive->id]))['delivers_as_inactive']);
        $list = $this->call('index');
        $this->assertTrue($this->listed($list, (int)$inactive->id)['delivers_as_inactive']);
        $this->assertFalse($this->listed($list, (int)$active->id)['delivers_as_inactive']);
        $this->assertSame((int)$gone->id, $this->listed($list, (int)$inactive->id)['delivers_as']);
    }

    /**
     * The list reads the filters and the users of its page at once: one
     * read of the workflow filters, however many templates the page holds.
     */
    public function testTheListReadsTheFiltersOfItsPageAtOnce(): void
    {
        $admin = $this->createUserWithRole('nt-api-list-reads', 'admin');
        $workflow = $this->createWorkflowTemplate((int)$admin->id);
        for ($i = 0; $i < 4; $i++) {
            $template = $this->createNotificationTemplate((int)$this->createUserWithRole('nt-api-list-reads-' . $i, 'operator')->id);
            $this->assertSame([], $template->setWorkflowTemplateIds([(int)$workflow->id], (int)$admin->id));
        }
        $this->authenticate($admin);

        $statements = $this->statementsOf(fn () => $this->call('index'));

        $filterReads = preg_grep('/^SELECT .* FROM `notification_template_workflow_template`/', $statements) ?: [];
        $identityReads = preg_grep('/^SELECT `id` FROM `user` WHERE/', $statements) ?: [];
        $this->assertCount(1, $filterReads, implode("\n", $statements));
        $this->assertCount(1, $identityReads, 'whom the templates deliver as, read once: ' . implode("\n", $statements));
    }

    /**
     * The signing secret of a webhook is answered as $encrypted$; sent back
     * so, it keeps the stored secret while url keeps its own, and is
     * refused once url changes, as the web form refuses it.
     */
    public function testTheSigningSecretOfAWebhookIsBoundToItsUrl(): void
    {
        $operator = $this->createUserWithRole('nt-api-signing', 'operator');
        $template = $this->createNotificationTemplate((int)$operator->id, NotificationTemplate::CHANNEL_WEBHOOK, 'job.failed', (string)json_encode(ChannelSecretFixtures::SIGNED_WEBHOOK, JSON_UNESCAPED_SLASHES));
        $this->authenticate($operator);

        $shown = $this->dataOf($this->call('view', ['id' => $template->id]))['config'];
        $this->assertIsArray($shown);
        $this->assertSame(SurveySecrets::PLACEHOLDER, $shown['signing_secret'] ?? null);
        $this->assertStringNotContainsString(ChannelSecretFixtures::SIGNING_SECRET, (string)json_encode($shown));

        $this->setBody(['config' => ['url' => 'https://other.example.com/hook', 'signing_secret' => SurveySecrets::PLACEHOLDER, 'payload' => 'event']]);
        $refused = $this->call('update', ['id' => $template->id]);
        $this->assertSame(422, \Yii::$app->response->statusCode);
        $this->assertSame(['error' => ['message' => 'signing_secret: url changed; enter the real value.']], $refused);
        $this->assertSame(ChannelSecretFixtures::SIGNED_WEBHOOK, $this->reload((int)$template->id)->getParsedConfig());

        $this->setBody(['config' => $shown]);
        $this->call('update', ['id' => $template->id]);
        $this->assertSame(200, \Yii::$app->response->statusCode);
        $this->assertSame(ChannelSecretFixtures::SIGNED_WEBHOOK, $this->reload((int)$template->id)->getParsedConfig());
    }

    // -- Fixtures -----------------------------------------------------------------

    /**
     * Two teams and a workflow template of each: teamA's has a job step of
     * a project that only team A sees, teamB's of one that only team B
     * sees, open's of a project of no team. viewerB is a viewer of team B,
     * editorA an operator of team A.
     *
     * @return array{scope: array{member: User, admin: User, outsider: User, own: JobTemplate, viewed: JobTemplate, foreign: JobTemplate, open: JobTemplate}, admin: User, viewerB: User, editorA: User, teamA: WorkflowTemplate, teamB: WorkflowTemplate, open: WorkflowTemplate}
     */
    private function teams(): array
    {
        $scope = $this->teamScope('viewer');
        $adminId = (int)$scope['admin']->id;

        return [
            'scope' => $scope,
            'admin' => $scope['admin'],
            'viewerB' => $scope['member'],
            'editorA' => $scope['outsider'],
            'teamA' => $this->createWorkflowWithJobSteps($adminId, (int)$scope['foreign']->id),
            'teamB' => $this->createWorkflowWithJobSteps($adminId, (int)$scope['own']->id),
            'open' => $this->createWorkflowWithJobSteps($adminId, (int)$scope['open']->id),
        ];
    }

    /**
     * An admin's template, scope selected, whose filter holds $workflows.
     *
     * @param array{admin: User} $world
     * @param list<WorkflowTemplate> $workflows
     */
    private function filteredTemplate(array $world, array $workflows): NotificationTemplate
    {
        $adminId = (int)$world['admin']->id;
        $template = $this->createNotificationTemplate($adminId, NotificationTemplate::CHANNEL_EMAIL, 'workflow.failed');
        $template->workflow_scope = NotificationTemplate::WORKFLOW_SCOPE_SELECTED;
        $this->assertTrue($template->save(false));
        $this->assertSame([], $template->setWorkflowTemplateIds(array_map(static fn (WorkflowTemplate $w): int => (int)$w->id, $workflows), $adminId));

        return $template;
    }

    /**
     * The body of a new email template, with $fields on top.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function emailBody(array $fields = []): array
    {
        return $fields + [
            'name' => 'nt-api-' . uniqid('', true),
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'events' => 'workflow.failed',
            'config' => ['emails' => ['ops@example.com']],
        ];
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function call(string $action, array $params = []): array
    {
        \Yii::$app->response->statusCode = 200;
        $result = (new NotificationTemplatesController('api/v1/notification-templates', $this->app()))->runAction($action, $params);
        $this->assertIsArray($result);
        $named = [];
        foreach ($result as $key => $value) {
            $named[(string)$key] = $value;
        }

        return $named;
    }

    /**
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private function dataOf(array $answer): array
    {
        $data = $answer['data'] ?? null;
        $this->assertIsArray($data, (string)json_encode($answer));
        $named = [];
        foreach ($data as $key => $value) {
            $named[(string)$key] = $value;
        }

        return $named;
    }

    /**
     * The template $id of a list's answer.
     *
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private function listed(array $answer, int $id): array
    {
        $item = $this->listItem($answer, $id);
        $named = [];
        foreach ($item as $key => $value) {
            $named[(string)$key] = $value;
        }

        return $named;
    }

    private function authenticate(User $user): void
    {
        ['raw' => $raw] = ApiToken::generate((int)$user->id, 'nt-api-token');
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $raw);
        \Yii::$app->user->setIdentity(null);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function setBody(array $body): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams($body);
    }

    private function reload(int $id): NotificationTemplate
    {
        $stored = NotificationTemplate::findOne($id);
        $this->assertInstanceOf(NotificationTemplate::class, $stored);

        return $stored;
    }

    /**
     * The contexts of the audit entries $action of template $id.
     *
     * @return list<array<string, mixed>>
     */
    private function auditEntries(string $action, int $id): array
    {
        $contexts = [];
        foreach (AuditLog::find()->where(['action' => $action, 'object_type' => 'notification_template', 'object_id' => $id])->orderBy(['id' => SORT_ASC])->all() as $entry) {
            $this->assertInstanceOf(AuditLog::class, $entry);
            $context = json_decode((string)$entry->metadata, true);
            $this->assertIsArray($context);
            $contexts[] = $context;
        }

        return $contexts;
    }

    /**
     * The SQL statements $action sends, in order.
     *
     * @return list<string>
     */
    private function statementsOf(callable $action): array
    {
        $logger = new Logger();
        $logger->flushInterval = PHP_INT_MAX;
        $previous = \Yii::getLogger();
        \Yii::setLogger($logger);
        try {
            $action();
        } finally {
            \Yii::setLogger($previous);
        }
        $statements = [];
        foreach ($logger->messages as $message) {
            if (in_array($message[2], ['yii\db\Command::query', 'yii\db\Command::execute'], true) && $message[1] === Logger::LEVEL_INFO && is_string($message[0])) {
                $statements[] = $message[0];
            }
        }

        return $statements;
    }
}
