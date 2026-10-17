<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\components\JobInventory;
use app\controllers\JobController;
use app\models\Inventory;
use app\models\Job;
use app\models\JobTemplate;
use app\models\User;
use app\services\JobLaunchService;
use app\tests\integration\services\runstate\RunStateFixtures;
use app\tests\integration\TeamScopeFixtures;
use yii\base\Module;
use yii\helpers\Html;

/**
 * The job page names the inventory a job was launched with
 * (#detail-inventory, views/job/_inventory.php), right after its job
 * template: as the job's runner payload records it (JobInventory), by its
 * name at launch, also once the inventory was renamed, the template moved
 * to another inventory, or the inventory was deleted. A live inventory is
 * linked to its page; a deleted one is named and marked "(deleted)", muted
 * and without a link; a job without an inventory, such as the placeholder
 * of an approval step, shows a dash.
 *
 * Jobs are launched by the real launch service, which records the inventory
 * as a launch does; jobs from before 2.12.0 get the payload of that time,
 * which names no inventory name.
 */
class JobPageInventoryTest extends WebControllerTestCase
{
    use RendersRealViews;
    use TeamScopeFixtures;
    use RunStateFixtures;
    use WaitingRunFixtures;
    use InventoryRequestFixtures;

    private User $admin;
    private int $adminId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUserWithRole('jpi-admin', 'admin');
        $this->adminId = (int)$this->admin->id;
        $this->loginAs($this->admin);
    }

    public function testTheJobPageLinksTheInventoryTheJobWasLaunchedWith(): void
    {
        $inventory = $this->inventoryNamed($this->adminId, 'web-prod');
        $job = $this->launch($this->templateOnInventory($inventory, $this->adminId));

        $page = $this->jobPage($job);

        $this->assertInventoryParam($page['inventory'], (int)$inventory->id, 'web-prod', true);
        $cell = $this->inventoryCell($page['html']);
        $this->assertSame('web-prod', self::textOf($cell));
        $this->assertSame(
            '<a href="/inventory/view?id=' . $inventory->id . '" data-inventory-id="' . $inventory->id . '">web-prod</a>',
            trim(self::innerHtmlOf($cell))
        );
        $this->assertMatchesRegularExpression(
            '#<dt class="col-5">Template</dt>\s*<dd class="col-7">.*?</dd>\s*'
            . '<dt class="col-5">Inventory</dt>\s*<dd class="col-7" id="detail-inventory">#s',
            $page['html'],
            'the Inventory row follows the Template row'
        );
    }

    /**
     * The page names the inventory as it was at launch: renamed since, and
     * with the template moved to another inventory, the job still ran on
     * the one it was launched with.
     */
    public function testTheInventoryKeepsItsNameAtLaunchAfterARenameAndAMove(): void
    {
        $inventory = $this->inventoryNamed($this->adminId, 'web-prod');
        $template = $this->templateOnInventory($inventory, $this->adminId);
        $job = $this->launch($template);
        $inventory->name = 'web-prod-renamed';
        $inventory->save(false);
        $this->moveTemplateTo($template, $this->inventoryNamed($this->adminId, 'db-prod'));

        $page = $this->jobPage($job);

        $this->assertInventoryParam($page['inventory'], (int)$inventory->id, 'web-prod', true);
        $cell = $this->inventoryCell($page['html']);
        $this->assertSame('web-prod', self::textOf($cell));
        $this->assertSame(['/inventory/view?id=' . $inventory->id], $this->linksOf($cell));
    }

    /**
     * A deleted inventory is named as it was at launch and marked deleted,
     * muted and without a link, as the page marks a deleted job template.
     */
    public function testADeletedInventoryIsNamedAndMarkedDeleted(): void
    {
        $inventory = $this->inventoryNamed($this->adminId, 'web-prod');
        $template = $this->templateOnInventory($inventory, $this->adminId);
        $job = $this->launch($template);
        $this->moveTemplateTo($template, $this->inventoryNamed($this->adminId, 'db-prod'));
        $this->assertSame(1, Inventory::deleteAll(['id' => $inventory->id]));

        $page = $this->jobPage($job);

        $this->assertInventoryParam($page['inventory'], (int)$inventory->id, 'web-prod', false);
        $cell = $this->inventoryCell($page['html']);
        $this->assertSame('web-prod (deleted)', self::textOf($cell));
        $this->assertSame('web-prod <span class="text-muted">(deleted)</span>', trim(self::innerHtmlOf($cell)));
        $this->assertSame([], $this->linksOf($cell));
    }

    /**
     * A job launched before 2.12.0 recorded the inventory's ID but not its
     * name: the page names it by the inventory's current name, and once the
     * row is gone by its ID.
     */
    public function testAJobLaunchedBefore2120IsNamedByTheInventoryRowOrItsId(): void
    {
        $inventory = $this->inventoryNamed($this->adminId, 'legacy-hosts');
        $template = $this->templateOnInventory($inventory, $this->adminId);
        $job = $this->createJob((int)$template->id, $this->adminId, Job::STATUS_SUCCEEDED);
        $job->runner_payload = (string)json_encode(['template_id' => (int)$template->id, 'inventory_id' => (int)$inventory->id, 'extra_vars' => null]);
        $job->save(false);

        $live = $this->inventoryCell($this->jobPage($job)['html']);
        $this->assertSame('legacy-hosts', self::textOf($live));
        $this->assertSame(['/inventory/view?id=' . $inventory->id], $this->linksOf($live));

        $this->moveTemplateTo($template, $this->inventoryNamed($this->adminId, 'db-prod'));
        $this->assertSame(1, Inventory::deleteAll(['id' => $inventory->id]));
        $page = $this->jobPage($job);

        $this->assertInventoryParam($page['inventory'], (int)$inventory->id, null, false);
        $gone = $this->inventoryCell($page['html']);
        $this->assertSame(sprintf('Inventory #%d (deleted)', $inventory->id), self::textOf($gone));
        $this->assertSame(
            sprintf(JobInventory::UNNAMED, $inventory->id) . ' <span class="text-muted">' . JobInventory::DELETED . '</span>',
            trim(self::innerHtmlOf($gone))
        );
        $this->assertSame([], $this->linksOf($gone));
    }

    /**
     * The placeholder of an approval step has no inventory, nor has a job
     * whose launch record names none: a dash, without a link.
     */
    public function testAJobWithoutAnInventoryShowsADash(): void
    {
        $wait = $this->approvalWait($this->admin, 'Inventory workflow', 'Sign-off', $this->ruleOf($this->adminId, [$this->admin]));
        $template = $this->templateOnInventory($this->inventoryNamed($this->adminId, 'web-prod'), $this->adminId);
        $unrecorded = $this->createJob((int)$template->id, $this->adminId, Job::STATUS_SUCCEEDED);
        $this->assertNull($unrecorded->runner_payload);

        foreach (['the placeholder of an approval step' => $wait['job'], 'a job without a launch record' => $unrecorded] as $case => $job) {
            $page = $this->jobPage($job);

            $this->assertInventoryParam($page['inventory'], null, null, false);
            $cell = $this->inventoryCell($page['html']);
            $this->assertSame('—', self::textOf($cell), $case);
            $this->assertSame(JobInventory::NONE, self::textOf($cell), $case);
            $this->assertSame([], $this->linksOf($cell), $case);
        }
    }

    public function testInventoryNamesAreEncoded(): void
    {
        $evil = '<img src=x onerror=alert(1)> "q" \'a\'';
        $inventory = $this->inventoryNamed($this->adminId, $evil);
        $template = $this->templateOnInventory($inventory, $this->adminId);
        $job = $this->launch($template);

        $html = $this->jobPage($job)['html'];

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('data-inventory-id="' . $inventory->id . '">' . Html::encode($evil) . '</a>', $html);
        $this->assertSame($evil, self::textOf($this->inventoryCell($html)));

        $this->moveTemplateTo($template, $this->inventoryNamed($this->adminId, 'db-prod'));
        $this->assertSame(1, Inventory::deleteAll(['id' => $inventory->id]));
        $html = $this->jobPage($job)['html'];

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString(Html::encode($evil) . ' <span class="text-muted">(deleted)</span>', $html);
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * A launch of $template by the real launch service, which records the
     * inventory in the job's runner payload.
     */
    private function launch(JobTemplate $template): Job
    {
        /** @var JobLaunchService $launcher */
        $launcher = \Yii::$app->get('jobLaunchService');
        $job = $launcher->launch($template, $this->adminId);
        $this->assertSame(Job::STATUS_QUEUED, $job->status, 'an ungated launch queues its job');

        return $job;
    }

    private function assertInventoryParam(mixed $inventory, ?int $id, ?string $name, bool $exists): void
    {
        $this->assertInstanceOf(JobInventory::class, $inventory, 'the controller hands the view the job\'s inventory');
        $this->assertSame([$id, $name, $exists], [$inventory->id, $inventory->name, $inventory->exists]);
    }

    private function inventoryCell(string $html): ?\DOMElement
    {
        $cells = $this->xpathOf($html)->query('//dl/dd[@id="detail-inventory"]');
        $this->assertNotFalse($cells);
        $this->assertSame(1, $cells->length, 'the job page has one Inventory row');
        $cell = $cells->item(0);

        return $cell instanceof \DOMElement ? $cell : null;
    }

    /**
     * @return list<string> the targets of the links in $cell
     */
    private function linksOf(?\DOMElement $cell): array
    {
        $hrefs = [];
        foreach ($cell?->getElementsByTagName('a') ?? [] as $link) {
            $hrefs[] = $link->getAttribute('href');
        }

        return $hrefs;
    }

    /**
     * The job page as the signed-in user sees it, and the inventory the
     * controller handed its view.
     *
     * @return array{html: string, inventory: mixed}
     */
    private function jobPage(Job $job): array
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Module::class, $app);
        $ctrl = new class ('job', $app) extends JobController {
            /** @var array<string, mixed> */
            public array $capturedParams = [];

            public function render($view, $params = []): string
            {
                /** @var array<string, mixed> $params */
                $this->capturedParams = $params;

                return $this->renderPartial($view, $params);
            }
        };
        $html = $this->withRealViews($ctrl, '/job/view?id=' . $job->id, static fn (): string => $ctrl->actionView((int)$job->id));

        return ['html' => $html, 'inventory' => $ctrl->capturedParams['jobInventory'] ?? null];
    }
}
