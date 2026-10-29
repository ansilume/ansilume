<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\controllers\BaseController;
use app\controllers\traits\TeamScopingTrait;
use app\models\Inventory;
use app\models\JobTemplate;
use app\tests\integration\TeamScopeFixtures;
use yii\db\BaseActiveRecord;
use yii\web\ForbiddenHttpException;

/**
 * The team scope checks of the web controllers (TeamScopingTrait) on a
 * project that a request submitted, as load() assigns it: a JSON body gives
 * numbers as int or float, true and false, and lists, a form gives text.
 *
 * Every form of a project ID that the model's integer rule accepts is
 * checked as the project the save stores ("05", "+5" and 5.0 are 5); null,
 * '', '0' and [] are no project, which every user may reach, as before. Any
 * other value names no project that a check could clear, so the checks
 * refuse it (fail closed), and refusesSubmittedProject() lets the form show
 * the model's error for it before the check runs.
 *
 * Regression: requireChildView() and requireChildOperate() cast such a
 * value with (int). On PHP 8.5 that cast warns for a JSON number beyond the
 * int range (1e30, 9223372036854775808, 1e999), the error handler turns the
 * warning into an exception, and the page answered 500 (web POST
 * /inventory/update as JSON {"Inventory": {"project_id": 1e30}}). Up to PHP
 * 8.4 the cast made such a number an arbitrary project, 1e999, text and
 * false project 0, which every user may reach, and true project 1.
 */
class TeamScopingSubmittedProjectTest extends WebControllerTestCase
{
    use SendsJsonNumbers;
    use TeamScopeFixtures;

    private const VIEW_REFUSED = 'You do not have access to this resource.';

    /**
     * Forms of a project ID that the integer rule accepts, as JSON values;
     * %d is the ID.
     *
     * @return array<string, array{0: string}>
     */
    public static function idFormProvider(): array
    {
        return [
            'the ID' => ['%d'],
            'the ID as digits' => ['"%d"'],
            'a leading zero' => ['"0%d"'],
            'a plus sign' => ['"+%d"'],
            'a whole float' => ['%d.0'],
        ];
    }

    /**
     * @dataProvider idFormProvider
     */
    public function testEveryFormOfAProjectIdIsCheckedAsThatProject(string $form): void
    {
        ['member' => $member, 'own' => $own, 'viewed' => $viewed, 'foreign' => $foreign] = $this->teamScope();
        $this->loginAs($member);
        $subject = $this->subject();

        $subject->view($this->submitted($form, (int)$own->project_id));
        $subject->operate($this->submitted($form, (int)$own->project_id));
        $subject->view($this->submitted($form, (int)$viewed->project_id));
        $this->assertRefused(fn () => $subject->operate($this->submitted($form, (int)$viewed->project_id)), BaseController::MODIFY_FORBIDDEN);
        $this->assertRefused(fn () => $subject->view($this->submitted($form, (int)$foreign->project_id)), self::VIEW_REFUSED);
        $this->assertRefused(fn () => $subject->operate($this->submitted($form, (int)$foreign->project_id)), BaseController::MODIFY_FORBIDDEN);
        $this->assertFalse($subject->refuses($this->inventoryWith($this->submitted($form, (int)$foreign->project_id))), 'an ID goes to the check');
    }

    /**
     * No project: what the form posts for none, and the values the model's
     * rules take for none.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function noProjectProvider(): array
    {
        return ['null' => [null], 'empty text' => [''], 'zero as text' => ['0'], 'an empty list' => [[]]];
    }

    /**
     * @dataProvider noProjectProvider
     */
    public function testNoProjectIsReachedByEveryUser(mixed $none): void
    {
        $this->loginAs($this->teamScope()['member']);
        $subject = $this->subject();

        $subject->view($none);
        $subject->operate($none);
        $this->assertFalse($subject->refuses($this->inventoryWith($none)));
    }

    /**
     * Values that are no project ID: JSON numbers beyond the int range, a
     * fraction, text, and true, false or a list; %d is a project's ID.
     *
     * @return array<string, array{0: string}>
     */
    public static function noProjectIdProvider(): array
    {
        return [
            '1e30' => ['1e30'],
            'one more than the largest int' => ['9223372036854775808'],
            '1e999, infinity' => ['1e999'],
            'minus 1e30' => ['-1e30'],
            'a fraction' => ['%d.5'],
            'decimal text' => ['"%d.0"'],
            'text' => ['"abc"'],
            'digits and text' => ['"%dabc"'],
            'a space before the digits' => ['" %d"'],
            'exponent text' => ['"%de0"'],
            'true' => ['true'],
            'false' => ['false'],
            'a list of the ID' => ['[%d]'],
        ];
    }

    /**
     * Even for users who may reach every project, and for a value the cast
     * made an open project.
     *
     * @dataProvider noProjectIdProvider
     */
    public function testAValueThatIsNoProjectIdIsRefusedNeverTakenForNone(string $form): void
    {
        ['member' => $member, 'admin' => $admin, 'open' => $open] = $this->teamScope();
        $value = $this->submitted($form, (int)$open->project_id);

        foreach ([$member, $admin] as $user) {
            $this->loginAs($user);
            $subject = $this->subject();
            $this->assertRefused(fn () => $subject->view($value), self::VIEW_REFUSED);
            $this->assertRefused(fn () => $subject->operate($value), BaseController::MODIFY_FORBIDDEN);
        }
    }

    /**
     * refusesSubmittedProject(): the model's integer rule reports such a
     * value on the model, before any check, for the form to show.
     *
     * @dataProvider noProjectIdProvider
     */
    public function testTheFormReportsAValueThatIsNoProjectIdBeforeTheCheck(string $form): void
    {
        $this->loginAs($this->teamScope()['member']);
        $subject = $this->subject();
        $inventory = $this->inventoryWith($this->submitted($form, 1));
        $template = new JobTemplate();
        $template->setAttribute('project_id', $this->submitted($form, 1));

        $this->assertTrue($subject->refuses($inventory));
        $this->assertSame(['project_id' => ['Project Id must be an integer.']], $inventory->getErrors());
        $this->assertTrue($subject->refuses($template));
        $this->assertSame(['Project Id must be an integer.'], $template->getErrors('project_id'));
    }

    /**
     * A guest is refused whatever the value, as before.
     */
    public function testAGuestIsRefused(): void
    {
        $subject = $this->subject();

        $this->assertRefused(fn () => $subject->view(null), self::VIEW_REFUSED);
        $this->assertRefused(fn () => $subject->operate('1e30'), BaseController::MODIFY_FORBIDDEN);
    }

    // -- helpers -------------------------------------------------------------------

    /**
     * The JSON value $form, with %d as $projectId, as the request's JSON
     * parser decodes it (a number beyond the int range is a float).
     */
    private function submitted(string $form, int $projectId): mixed
    {
        $body = $this->sendJsonText('{"project_id": ' . sprintf($form, $projectId) . '}');

        return $body['project_id'];
    }

    private function inventoryWith(mixed $projectId): Inventory
    {
        $inventory = new Inventory();
        $inventory->setAttribute('project_id', $projectId);

        return $inventory;
    }

    /**
     * @param callable(): void $check
     */
    private function assertRefused(callable $check, string $message): void
    {
        try {
            $check();
            $this->fail('the check refuses it');
        } catch (ForbiddenHttpException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    /**
     * An object that uses the trait, as the web controllers do.
     */
    private function subject(): object
    {
        return new class () {
            use TeamScopingTrait;

            public function view(mixed $projectId): void
            {
                $this->requireChildView($projectId);
            }

            public function operate(mixed $projectId): void
            {
                $this->requireChildOperate($projectId);
            }

            public function refuses(BaseActiveRecord $model): bool
            {
                return $this->refusesSubmittedProject($model);
            }
        };
    }
}
