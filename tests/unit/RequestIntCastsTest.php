<?php

declare(strict_types=1);

namespace app\tests\unit;

use app\helpers\FileHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression (PHP 8.5): an (int) cast, intval(), settype() to int and the
 * %d of sprintf() warn there for a float beyond the int range ("The float
 * 1.0E+30 is not representable as an int, cast occurred"), and the error
 * handler turns the warning into an exception. A JSON body, which Yii's
 * JsonParser and json_decode() read, gives 1e30, 9223372036854775808 and
 * 1e999 (infinity) as such floats, so on PHP 8.5 the API of 2.13.1, its web
 * forms posted as JSON, the launch and the runner's reports answered 500 for
 * them, from (int)$body[$field] of the job template API and
 * (int)($body['user_id'] ?? 0) of the team members to the verbosity of a
 * launch. Up to PHP 8.4 the cast wrapped such a number to an arbitrary int,
 * or made infinity 0, and went on with it.
 *
 * The code reads a submitted whole number with SubmittedInteger::of() now,
 * or ::orAsSubmitted() where the model's integer rule refuses what is none,
 * and an ID with SubmittedId::of(), as the integer rule reads them. This
 * test keeps the conversions out of the application code
 * (RequestIntCastScan::sourceFilesIn(): commands, components, config,
 * controllers, helpers, jobs, models, services, views, web and the yii
 * script), as RequestIntCastScan finds them in the tokens of the code:
 * - an int conversion of a request value, read in any of the ways the code
 *   reads one (RequestIntCastScan::REQUEST_VALUE);
 * - an int conversion that only is_numeric(), is_scalar() or is_float()
 *   guards (RequestIntCastScan::TYPE_CHECK), whatever the value: each of
 *   them is true for such a float, so none is a guard for an int
 *   conversion.
 * In the application code of 2.13.1 (c017f1c) the scan finds 22
 * conversions of a request value and 26 that only a type check guarded, in
 * the approval gates, the claim and the launch among them: 48 in 30 files,
 * each on a line that the move to PHP 8.5 changed.
 * testTheScanFindsTheConversionsOf2131() keeps one of each shape.
 *
 * What the scan cannot see, which SubmittedNumbersApiTest and
 * SubmittedNumbersWebFormTest find for every whole-number field of the API,
 * the triggers, the runner API and the web forms, and the regression tests
 * of each site where they reach it:
 * - a request value that reaches the conversion as a parameter, a property
 *   or a model attribute (TeamScopingTrait's $value, $model->project_id
 *   after load(), $this->credential_id of JobTemplate), as an attribute
 *   decoded from one (an approval rule's approver config), through two
 *   variables or a function that returns it (ArrayHelper::getValue());
 * - a number computed from one ((int)round($body['x'])), a format that is
 *   no quoted string or class constant, and a callback other than intval;
 * - a guard in alternative syntax (if (…):) or with and, or and xor.
 * Query values (->get(), ->getQueryParam()) are text or lists, which
 * convert without a warning, and are no request value here. The scan takes
 * for one what is none in two cases: a variable named $body or $overrides
 * that holds something else, and post() of an HTTP client.
 */
class RequestIntCastsTest extends TestCase
{
    /**
     * The sites in the application code, once for all tests (repository()):
     * "<path>:<line>: <code>" by kind.
     *
     * @var array<string, list<string>>|null
     */
    private static ?array $repository = null;

    private string $directory = '';

    protected function tearDown(): void
    {
        if ($this->directory !== '') {
            FileHelper::removeDirectory($this->directory);
        }
    }

    public function testNoCodeConvertsARequestValueToAnInt(): void
    {
        $this->assertSame(
            [],
            self::repository()[RequestIntCastScan::REQUEST_VALUE] ?? [],
            'PHP 8.5 warns when (int), intval(), settype() or a %d format converts a float beyond the int range,'
            . ' which a JSON body gives for 1e30, 9223372036854775808 or 1e999, and the error handler turns the'
            . ' warning into an exception, so the request answers 500: read a submitted whole number with'
            . ' SubmittedInteger::of(), or ::orAsSubmitted() for the model\'s integer rule to refuse what is none,'
            . ' and an ID with SubmittedId::of()'
        );
    }

    public function testNoCodeConvertsAValueThatOnlyATypeCheckGuards(): void
    {
        $this->assertSame(
            [],
            self::repository()[RequestIntCastScan::TYPE_CHECK] ?? [],
            'is_numeric(), is_scalar() and is_float() are true for a float beyond the int range, for which PHP 8.5'
            . ' warns when it converts it to an int: they are no guard for an int conversion. Read the number with'
            . ' SubmittedInteger::of() or SubmittedId::of(), or check its range before the conversion'
        );
    }

    /**
     * Each way the code reads a request value, converted to an int in each
     * way (a format given as a class constant too): directly, through
     * parentheses, ??, ?: and ? :, through a variable its function assigned
     * it to, and as the value of a foreach over it; in a function and in a
     * view. A variable holds one until it is assigned anew, from the end of
     * that assignment on: $value = (int)$value still converts one.
     */
    public function testTheScanFindsEachRequestValue(): void
    {
        $scan = new RequestIntCastScan(
            static fn (string $class, string $name): ?string => $class === 'Messages' && $name === 'LIMIT'
                ? 'at most %d'
                : null
        );
        $lines = self::linesByKind($scan, <<<'PHP'
            <?php
            function shapes(\yii\web\Request $request, array $body, array $overrides, string $name): void
            {
                $a = (int)$body['id'];
                $b = (int)($body['user_id'] ?? 0);
                $c = (int)$body['a'] + 1;
                $d = intval($body['n']);
                $e = \intval($request->post('n'), 10);
                settype($body['x'], 'int');
                settype($body['y'], 'INTEGER');
                $f = (int)\Yii::$app->request->post('step_id');
                $g = (int)$request->post('Form')['field'];
                $h = (int)$request->getBodyParam('n');
                $i = (int)$request->getBodyParams()['n'];
                $j = (int)\Yii::$app->request->bodyParams['n'];
                $k = (int)$request->getRawBody();
                $l = (int)json_decode($request->getRawBody(), true)['n'];
                $m = (int)(\Yii::$app->request->get('id') ?? \Yii::$app->request->post('id', 0));
                $n = (int)($request?->post('a') ?: 0);
                $o = (int)($name !== '' ? $body['b'] : 0);
                $p = ( int )$overrides['verbosity'];
                $q = sprintf('%d of %s', $body['n'], $name);
                $r = sprintf('%2$s %1$05d', $body['n'], $name);
                $s = vsprintf('%x', [$body['n']]);
                $t = sprintf(Messages::LIMIT, $body['n']);
                $u = array_map('intval', $body['ids']);
                $v = array_map(intval(...), $request->post('ids', []));
                $raw = $request->post('group_id');
                $w = (int)$raw;
                $form = (array)\Yii::$app->request->bodyParams;
                $x = (int)$form['n'];
                foreach ($body['tasks'] as $key => $task) {
                    $y = (int)$task['seq'] + (int)$key;
                }
                $value = $body['a'];
                $value = (int)$value;
                $z = (int)$value;
            }
            PHP);

        $this->assertSame([RequestIntCastScan::REQUEST_VALUE => [
            4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 29, 31, 33, 36,
        ]], $lines);

        $view = self::linesByKind(new RequestIntCastScan(), <<<'PHP'
            <?php $posted = \Yii::$app->request->post('Form'); ?>
            <p><?= (int)$posted['count'] ?></p>
            <p><?= sprintf('%d', $posted['total']) ?></p>
            PHP);
        $this->assertSame([RequestIntCastScan::REQUEST_VALUE => [2, 3]], $view);
    }

    /**
     * Each way an is_numeric(), is_scalar() or is_float() check guards an
     * int conversion of the same value: `? :`, `&&`, `||`, an if, elseif
     * or else around it, braced or not, and an earlier if that returns or
     * continues.
     */
    public function testTheScanFindsEachTypeCheck(): void
    {
        $lines = self::linesByKind(new RequestIntCastScan(), <<<'PHP'
            <?php
            function checks($value, $page, $verbosity, array $raw, ?array $entry, array $list, string $field): mixed
            {
                $a = is_numeric($value) ? (int)$value : 0;
                $b = is_numeric($page) ? max(1, (int)$page) : 1;
                $c = is_numeric($value) && (int)$value > 0;
                $d = !is_numeric($value) || (int)$value <= 0;
                $e = is_numeric($raw['id'] ?? null) ? (int)$raw['id'] : 0;
                $f = is_scalar($verbosity) ? (int)$verbosity : $verbosity;
                $g = is_float($value) ? intval($value) : 0;
                $h = !is_numeric($value) ? null : (int)$value;
                $i = array_map(static fn (mixed $id): int => is_numeric($id) ? (int)$id : 0, $list);
                $j = is_numeric($value) ? sprintf('#%d', $value) : '';
                if (is_array($entry) && is_numeric($entry['id'] ?? null)) {
                    $k = (int)$entry['id'];
                }
                if (($field === 'a' || $field === 'b') && is_numeric($value)) {
                    $l = (int)$value;
                }
                if (is_numeric($value)) $m = (int)$value;
                if ($value === []) {
                    $n = 0;
                } elseif (is_numeric($value)) {
                    $n = (int)$value;
                }
                if (!is_numeric($value)) {
                    $o = 0;
                } else {
                    $o = (int)$value;
                }
                foreach ($list as $item) {
                    if (!is_numeric($item)) {
                        continue;
                    }
                    $p = (int)$item;
                }
                if ($value === null || $value === '') {
                    return null;
                }
                if (!is_numeric($value)) {
                    return null;
                }

                return (int)$value;
            }
            PHP);

        $this->assertSame(
            [RequestIntCastScan::TYPE_CHECK => [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 15, 18, 20, 24, 29, 35, 44]],
            $lines
        );
    }

    /**
     * Conversions that are no site: of what is no request value (the
     * user's ID, a model, a row, a query value, the result of
     * SubmittedInteger and SubmittedId, a count), a request value that is
     * no int conversion's, one assigned anew, one in another function, in
     * a string or a comment; and a type check with a range check or a
     * validation, of the other polarity, after the conversion, or of a
     * value assigned anew.
     */
    public function testTheScanFindsNothingElse(): void
    {
        $sites = (new RequestIntCastScan())->sitesIn(<<<'PHP'
            <?php
            final class Safe
            {
                public function shapes(Request $request, array $row, $value, $model, array $list, $validator): mixed
                {
                    $a = (int)\Yii::$app->user->id + (int)$model->id + (int)$row['x'];
                    $b = (int)$request->get('page', 1) + (int)\Yii::$app->request->getQueryParam('page');
                    $c = SubmittedInteger::of($request->post('n')) ?? SubmittedId::of($request->post('id')) ?? 0;
                    $d = (int)SubmittedId::of($request->post('id')) + (int)count($request->post('ids', []));
                    $e = (string)$request->post('name') . (float)$request->post('ratio');
                    $f = (bool)$request->post('become') && filter_var($request->post('n'), FILTER_VALIDATE_INT);
                    $g = sprintf('%s and %.2f', $request->post('name'), $request->post('ratio'));
                    $h = sprintf('%d', count($list)) . sprintf($request->post('format'), 1);
                    $i = sprintf(Messages::UNKNOWN, $request->post('n'));
                    $j = (int)$this->body['x'] + (int)Holder::$body['x'];
                    $k = (int)$request->posted + (int)$request->postal('x');
                    $l = settype($value, 'string') && array_map('strval', $request->post('ids'));
                    $m = array_map('intval', $list);
                    $raw = $request->post('id');
                    $raw = SubmittedId::of($raw);
                    $n = (int)$raw;
                    $o = "(int)$request->post {$request->post('x')}";
                    // (int)$request->post('x')
                    /* intval($request->post('y')) */
                    $p = $validator->validate($value) ? (int)$value : null;
                    $q = is_float($value) && $value >= -9.2e18 && $value < 9.2e18 ? (int)$value : null;
                    $r = is_numeric($value) ? (float)$value : null;
                    $s = is_string($value) && ctype_digit($value) ? (int)$value : null;
                    $t = is_int($value) ? (int)$value : 0;
                    $u = !is_numeric($value) ? (int)$value : 0;
                    $v = is_numeric($value) ? 0 : (int)$value;
                    $w = (int)$value ?: is_numeric($value);
                    $x = is_numeric($row['a']) ? (int)$row['b'] : 0;
                    if (!is_numeric($value)) {
                        $y = 0;
                    } elseif ($value > 100) {
                        $y = 100;
                    } else {
                        $y = (int)$value;
                    }
                    if (is_numeric($value)) {
                        $value = SubmittedInteger::of($value);
                        $z = (int)$value;
                    }
                    if (!is_numeric($value)) {
                        return null;
                    }
                    $value = SubmittedInteger::of($value);

                    return (int)$value;
                }

                public function other(): int
                {
                    return (int)$raw + (int)$task['seq'] + (int)$form['n'];
                }
            }
            PHP);

        $this->assertSame([], $sites);
    }

    /**
     * Regression: the conversions of 2.13.1 that answered 500 on PHP 8.5,
     * or that only a type check guarded, as they were, each in its class
     * (git show c017f1c:<path>). The scan finds each of them.
     *
     * @param list<string> $expected "<line> <kind>" of each site
     */
    #[DataProvider('conversionsOf2131Provider')]
    public function testTheScanFindsTheConversionsOf2131(string $code, array $expected): void
    {
        $found = [];
        foreach ((new RequestIntCastScan())->sitesIn($code) as $site) {
            $found[] = "{$site['line']} {$site['kind']}";
        }

        $this->assertSame($expected, $found);
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function conversionsOf2131Provider(): array
    {
        $request = RequestIntCastScan::REQUEST_VALUE;
        $check = RequestIntCastScan::TYPE_CHECK;

        return [
            'controllers/api/v1/JobTemplatesController.php applyIntFields()' => [<<<'PHP'
                <?php
                final class JobTemplatesController
                {
                    private function applyIntFields(JobTemplate $model, array $body): void
                    {
                        $fields = ['project_id', 'inventory_id', 'credential_id', 'verbosity', 'forks', 'timeout_minutes', 'runner_group_id'];
                        $nullable = ['credential_id', 'runner_group_id'];
                        foreach ($fields as $field) {
                            if (!array_key_exists($field, $body)) {
                                continue;
                            }
                            if ($body[$field] === null && in_array($field, $nullable, true)) {
                                $model->$field = null;
                            } else {
                                $model->$field = (int)$body[$field];
                            }
                        }
                    }
                }
                PHP, ["15 {$request}"]],
            'controllers/api/v1/TeamsController.php actionAddMember(): user_id' => [<<<'PHP'
                <?php
                final class TeamsController
                {
                    public function actionAddMember(int $id): array
                    {
                        $team = $this->findModel($id);
                        $body = (array)\Yii::$app->request->bodyParams;
                        $userId = (int)($body['user_id'] ?? 0);
                    }
                }
                PHP, ["8 {$request}"]],
            'services/JobLaunchInput.php errors(): verbosity' => [<<<'PHP'
                <?php
                final class JobLaunchInput
                {
                    public static function errors(array $overrides): array
                    {
                        $job = new Job();
                        $fields = [];
                        if (isset($overrides['verbosity'])) {
                            $verbosity = $overrides['verbosity'];
                            $job->setAttribute('verbosity', is_scalar($verbosity) ? (int)$verbosity : $verbosity);
                            $fields[] = 'verbosity';
                        }
                    }
                }
                PHP, ["10 {$request}", "10 {$check}"]],
            'services/GatedJobTemplateLock.php positiveId()' => [<<<'PHP'
                <?php
                final class GatedJobTemplateLock
                {
                    private static function positiveId(mixed $number): ?int
                    {
                        $id = is_numeric($number) ? (int)$number : 0;

                        return $id > 0 ? $id : null;
                    }
                }
                PHP, ["6 {$check}"]],
            'controllers/api/v1/JobsController.php templateIdFrom()' => [<<<'PHP'
                <?php
                final class JobsController
                {
                    private function templateIdFrom(#[\SensitiveParameter] array $body): ?int
                    {
                        $raw = $body['job_template_id'] ?? $body['template_id'] ?? null;
                        if (!is_numeric($raw) || (int)$raw <= 0) {
                            return null;
                        }

                        return (int)$raw;
                    }
                }
                PHP, ["7 {$request}", "7 {$check}", "11 {$request}", "11 {$check}"]],
            'controllers/RunnerController.php actionCreate(): group_id through a variable' => [<<<'PHP'
                <?php
                final class RunnerController
                {
                    public function actionCreate(): Response
                    {
                        /** @var int|string $rawGroupId */
                        $rawGroupId = \Yii::$app->request->post('group_id');
                        $groupId = (int)$rawGroupId;
                        /** @var RunnerGroup|null $group */
                        $group = RunnerGroup::findOne($groupId);
                    }
                }
                PHP, ["8 {$request}"]],
            'controllers/api/v1/InventoriesController.php applyBody(): project_id through a variable' => [<<<'PHP'
                <?php
                final class InventoriesController
                {
                    private function applyBody(Inventory $model, #[\SensitiveParameter] array $body): void
                    {
                        foreach (self::MODEL_FIELDS as $field) {
                            if (!array_key_exists($field, $body)) {
                                continue;
                            }
                            $value = $body[$field];
                            if (($value === null || $value === '') && in_array($field, self::NULLABLE_FIELDS, true)) {
                                $model->$field = null;
                            } elseif ($field === 'project_id') {
                                $model->project_id = (int)$value;
                            } else {
                                $model->$field = (string)$value;
                            }
                        }
                    }
                }
                PHP, ["14 {$request}"]],
            'controllers/WorkflowTemplateController.php actionLaunch() and actionMoveStep()' => [<<<'PHP'
                <?php
                final class WorkflowTemplateController
                {
                    public function actionLaunch(): Response
                    {
                        $id = (int)(\Yii::$app->request->get('id') ?? \Yii::$app->request->post('id', 0));
                        $model = $this->findModel($id);
                    }

                    public function actionMoveStep(int $id): Response
                    {
                        $model = $this->findModel($id);
                        $this->requireWorkflowOperate((int)$model->id);
                        $request = \Yii::$app->request;
                        $stepId = (int)$request->post('step_id');
                    }
                }
                PHP, ["6 {$request}", "15 {$request}"]],
            'controllers/api/runner/JobsController.php actionLogs(): sequence' => [<<<'PHP'
                <?php
                final class JobsController
                {
                    public function actionLogs(int $id): Response
                    {
                        $body = (array)\Yii::$app->request->bodyParams;
                        $content = (string)($body['content'] ?? '');
                        $sequence = (int)($body['sequence'] ?? 0);
                    }
                }
                PHP, ["8 {$request}"]],
            'services/JobLaunchService.php buildJobRecord(): the launch overrides' => [<<<'PHP'
                <?php
                final class JobLaunchService
                {
                    private function buildJobRecord(JobTemplate $template, array $overrides): Job
                    {
                        if (!empty($overrides['limit'])) {
                            $job->limit = (string)$overrides['limit'];
                        }

                        if (isset($overrides['verbosity'])) {
                            $job->verbosity = (int)$overrides['verbosity'];
                        }
                    }
                }
                PHP, ["11 {$request}"]],
            'services/JobCredentialResolver.php snapshotById(): an if around the cast' => [<<<'PHP'
                <?php
                final class JobCredentialResolver
                {
                    private static function snapshotById(array $raw): array
                    {
                        $byId = [];
                        foreach ((array)($raw[Job::PAYLOAD_CREDENTIAL_SNAPSHOT] ?? []) as $entry) {
                            if (is_array($entry) && is_numeric($entry['id'] ?? null)) {
                                $byId[(int)$entry['id']] = [
                                    'name' => isset($entry['name']) ? (string)$entry['name'] : null,
                                    'credential_type' => isset($entry['credential_type']) ? (string)$entry['credential_type'] : null,
                                ];
                            }
                        }

                        return $byId;
                    }
                }
                PHP, ["9 {$check}"]],
            'services/ApprovalRuleWriteService.php auditValue(): earlier ifs that return' => [<<<'PHP'
                <?php
                final class ApprovalRuleWriteService
                {
                    private static function auditValue(string $field, mixed $value): mixed
                    {
                        if ($value === null || $value === '') {
                            return null;
                        }
                        if ($field === 'approver_config' && is_string($value)) {
                            $decoded = json_decode($value, true);
                            return is_array($decoded) ? $decoded : $value;
                        }
                        if (($field === 'required_approvals' || $field === 'timeout_minutes') && is_numeric($value)) {
                            return (int)$value;
                        }

                        return $value;
                    }
                }
                PHP, ["14 {$check}"]],
            'services/JobTemplateRuleAssignment.php vetoesDifferentRules(): an early return' => [<<<'PHP'
                <?php
                final class JobTemplateRuleAssignment
                {
                    public static function vetoesDifferentRules(JobTemplate $template, bool $shareLock): bool
                    {
                        $ownRuleId = $template->getAttribute(self::FIELD);
                        if ($template->hasErrors(self::FIELD) || $template->hasErrors('inventory_id') || !is_numeric($ownRuleId)) {
                            return false;
                        }
                        $inventoryId = $template->getAttribute('inventory_id');

                        return self::vetoesPair($template, [(int)$ownRuleId, is_numeric($inventoryId) ? (int)$inventoryId : 0, false], $shareLock);
                    }
                }
                PHP, ["12 {$check}", "12 {$check}"]],
            'services/GatedInventoryLock.php isGated(): is_numeric() &&' => [<<<'PHP'
                <?php
                final class GatedInventoryLock
                {
                    public static function isGated(Inventory $inventory): bool
                    {
                        $ruleId = $inventory->getOldAttribute('approval_rule_id');

                        return is_numeric($ruleId) && (int)$ruleId > 0;
                    }
                }
                PHP, ["8 {$check}"]],
            'controllers/JobTemplateController.php formData(): an arrow function' => [<<<'PHP'
                <?php
                final class JobTemplateController
                {
                    private function formData(JobTemplate $model, ?array $selectedCredentialIds): array
                    {
                        return [
                            'selectedCredentialIds' => $selectedCredentialIds !== null
                                ? array_map(static fn (mixed $id): int => is_numeric($id) ? (int)$id : 0, $selectedCredentialIds)
                                : ($model->isNewRecord ? [] : $this->credentialService()->additionalIds($model)),
                        ];
                    }
                }
                PHP, ["8 {$check}"]],
        ];
    }

    /**
     * A site names its line and its code; a printf conversion the code of
     * its call.
     */
    public function testASiteSaysWhereAndWhat(): void
    {
        $sites = (new RequestIntCastScan())->sitesIn(<<<'PHP'
            <?php
            function f(array $body, mixed $value): void
            {
                $id = (int)(
                    $body['id'] ?? 0
                );
                $text = sprintf('#%d', $body['id']) . (is_numeric($value) ? intval($value, 10) : 0);
            }
            PHP);

        $this->assertSame([
            ['line' => 4, 'kind' => RequestIntCastScan::REQUEST_VALUE, 'says' => "(int)( \$body['id'] ?? 0 )"],
            ['line' => 7, 'kind' => RequestIntCastScan::REQUEST_VALUE, 'says' => "sprintf('#%d', \$body['id'])"],
            ['line' => 7, 'kind' => RequestIntCastScan::TYPE_CHECK, 'says' => 'intval($value, 10)'],
        ], $sites);
    }

    /**
     * A format given as a class constant is read from the class it names in
     * the file: self and static the class the file declares, an imported
     * name its import, any other one of the file's namespace. One the scan
     * does not know is read as no format.
     */
    public function testAFormatGivenAsAClassConstantIsReadFromItsClass(): void
    {
        $asked = [];
        $scan = new RequestIntCastScan(static function (string $class, string $name) use (&$asked): ?string {
            $asked[] = "{$class}::{$name}";

            return $name === 'LIMIT' ? 'at most %d of %s' : null;
        });
        $lines = self::linesByKind($scan, <<<'PHP'
            <?php
            namespace app\controllers;

            use app\models\Messages;
            use app\models\Other as Texts;

            final class LimitController
            {
                public function actionIndex(array $body): string
                {
                    return sprintf(self::LIMIT, $body['n']) . sprintf(Messages::LIMIT, $body['n'], 'x')
                        . sprintf(Texts::LIMIT . ' (%s)', $body['n'], 'y', 'z') . sprintf(Local::LIMIT, 'x', $body['n'])
                        . sprintf(\app\Fully::LIMIT, $body['n']) . sprintf(static::UNKNOWN, $body['n']);
                }
            }
            PHP);

        $this->assertSame([
            'app\controllers\LimitController::LIMIT',
            'app\models\Messages::LIMIT',
            'app\models\Other::LIMIT',
            'app\controllers\Local::LIMIT',
            'app\Fully::LIMIT',
            'app\controllers\LimitController::UNKNOWN',
        ], $asked);
        $this->assertSame([RequestIntCastScan::REQUEST_VALUE => [11, 11, 12, 13]], $lines);
    }

    /**
     * The repository's scan reads a format constant by reflection, private
     * ones too, from any class it can load; it knows none of a class it
     * cannot load, none that the class lacks and none that is no text.
     */
    public function testTheRepositoryReadsAFormatConstantOfItsClass(): void
    {
        $this->assertSame(\DateTimeInterface::ATOM, self::classConstant(\DateTimeInterface::class, 'ATOM'));
        $this->assertStringContainsString('intval', (string)self::classConstant(RequestIntCastScan::class, 'MENTIONS'));
        $this->assertNull(self::classConstant('app\models\NoSuchClass', 'ATOM'));
        $this->assertNull(self::classConstant(\DateTimeInterface::class, 'NO_SUCH_CONSTANT'));
        $this->assertNull(self::classConstant(\PDO::class, 'PARAM_INT'));
    }

    /**
     * The values a format converts to an int are those for which the running
     * PHP warns when they are a float beyond the int range: every integer
     * conversion, by its position or an argument number; not %% and not a
     * `*` width or precision, which takes a value of its own.
     *
     * @param list<int> $positions
     */
    #[DataProvider('formatProvider')]
    public function testTheIntArgumentsOfAFormatAreThoseThePhpConverts(string $format, array $positions): void
    {
        $warned = [];
        for ($position = 0; $position < 16; $position++) {
            $values = array_fill(0, 16, 7);
            $values[$position] = 1e30;
            $warning = false;
            set_error_handler(static function (int $level, string $message) use (&$warning): bool {
                $warning = $warning || str_contains($message, 'is not representable as an int');

                return true;
            });
            try {
                // PHP warns while it formats what it converts.
                $formatted = vsprintf($format, $values);
            } catch (\ValueError) {
                // A * width or precision takes an int only: a float is no width.
            } finally {
                restore_error_handler();
            }
            if ($warning) {
                $warned[] = $position;
            }
        }
        $found = array_values(array_unique(RequestIntCastScan::intArgumentsOf($format)));
        sort($found);

        $this->assertSame($positions, $warned, 'what the running PHP converts');
        $this->assertSame($positions, $found);
    }

    /**
     * @return array<string, array{0: string, 1: list<int>}>
     */
    public static function formatProvider(): array
    {
        return [
            'one' => ['%d', [0]],
            'after text' => ['%s is %d', [1]],
            'every conversion' => ['%b %c %d %e %E %f %F %g %G %h %H %o %s %u %x %X', [0, 1, 2, 11, 13, 14, 15]],
            'no %%' => ['100%% of %d', [0]],
            'an argument number' => ['%2$d of %1$s', [1]],
            'an argument number twice' => ['%1$s %1$d %s', [0]],
            'an argument number before the next' => ['%3$x %d', [0, 2]],
            'flags, width and precision' => ["%'*10d %-+05d %5.2f % d %ld", [0, 1, 3, 4]],
            'a * width' => ['%*d', [1]],
            'a * precision' => ['%.*f %d', [2]],
            'a * width by number' => ['%*2$d', [0]],
            'none' => ['%s and %.2f', []],
        ];
    }

    /**
     * The scan reads every PHP file of the application code and the yii
     * script, and nothing else: no tests, dependencies, migrations, mail
     * templates, runtime data or published assets.
     */
    public function testTheScanReadsTheApplicationCode(): void
    {
        $files = RequestIntCastScan::sourceFilesIn(dirname(__DIR__, 2));

        $expected = [
            'yii',
            'config/web.php',
            'controllers/api/v1/JobTemplatesController.php',
            'controllers/traits/TeamScopingTrait.php',
            'services/JobLaunchInput.php',
            'views/job-template/form.php',
            'web/index.php',
        ];
        foreach ($expected as $file) {
            $this->assertContains($file, $files);
        }
        $elsewhere = '#^(tests|vendor|migrations|mail|runtime|docker|web/assets)/#';
        $this->assertSame([], array_values(array_filter(
            $files,
            static fn (string $file): bool => preg_match($elsewhere, $file) === 1
        )));

        $this->directory = sys_get_temp_dir() . '/request_int_casts_' . uniqid('', true);
        $tree = [
            'yii', 'notes.txt', 'bin/release', 'mail/layouts/html.php', 'migrations/m1.php', 'tests/unit/T.php',
            'vendor/a/b.php', 'commands/C.php', 'components/D.php', 'config/web.php', 'controllers/A.php',
            'controllers/api/v1/B.php', 'helpers/E.php', 'jobs/F.php', 'models/G.php', 'services/H.php',
            'views/site/index.php', 'web/index.php', 'web/assets/1a2b/x.php', 'web/js/app.js',
        ];
        foreach ($tree as $file) {
            self::write($this->directory . '/' . $file);
        }

        $this->assertSame([
            'commands/C.php',
            'components/D.php',
            'config/web.php',
            'controllers/A.php',
            'controllers/api/v1/B.php',
            'helpers/E.php',
            'jobs/F.php',
            'models/G.php',
            'services/H.php',
            'views/site/index.php',
            'web/index.php',
            'yii',
        ], RequestIntCastScan::sourceFilesIn($this->directory));
    }

    /**
     * @return array<string, list<string>>
     */
    private static function repository(): array
    {
        if (self::$repository !== null) {
            return self::$repository;
        }
        $root = dirname(__DIR__, 2);
        $scan = new RequestIntCastScan(self::classConstant(...));
        $sites = [];
        foreach (RequestIntCastScan::sourceFilesIn($root) as $path) {
            foreach ($scan->sitesIn((string)file_get_contents($root . '/' . $path)) as $site) {
                $sites[$site['kind']][] = "{$path}:{$site['line']}: {$site['says']}";
            }
        }

        return self::$repository = $sites;
    }

    /**
     * The text of a class constant by reflection, so private ones too; null
     * for a class that does not load, a constant it lacks and one that is
     * no text.
     */
    private static function classConstant(string $class, string $name): ?string
    {
        if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
            return null;
        }
        $constant = (new \ReflectionClass($class))->getReflectionConstant($name);
        $value = $constant === false ? null : $constant->getValue();

        return is_string($value) ? $value : null;
    }

    /**
     * The lines of the sites $scan finds in $code, by kind.
     *
     * @return array<string, list<int>>
     */
    private static function linesByKind(RequestIntCastScan $scan, string $code): array
    {
        $lines = [];
        foreach ($scan->sitesIn($code) as $site) {
            $lines[$site['kind']][] = $site['line'];
        }

        return $lines;
    }

    private static function write(string $file): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o700, true);
        }
        file_put_contents($file, "<?php\n");
    }
}
