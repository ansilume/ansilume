<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var app\models\NotificationTemplate $model */
/** @var app\services\notification\NotificationTemplatePage $page What the page shows its viewer: whom it delivers as, its filter, its deliveries */

use app\components\NotificationConfigSecrets;
use app\components\SurveySecrets;
use app\helpers\TimeHelper;
use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\services\notification\NotificationTemplatePage;
use yii\helpers\Html;

$this->title = $model->name;
// The config with the placeholder for every channel secret: every user who
// may view the template sees this page (the default role viewer).
$shownConfig = $model->getRedactedConfig();
/** @var list<NotificationDelivery> $deliveries */
$deliveries = array_values($page->deliveries->getModels());
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><?= Html::encode($this->title) ?></h2>
    <div>
        <?php if (Yii::$app->user->can('notification-template.update')) : ?>
            <form action="<?= \yii\helpers\Url::to(['test', 'id' => $model->id]) ?>" method="post" style="display:inline">
                <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->csrfToken ?>">
                <button type="submit" class="btn btn-outline-secondary btn-sm">Send Test</button>
            </form>
            <?= Html::a('Edit', ['update', 'id' => $model->id], ['class' => 'btn btn-outline-primary btn-sm ms-1']) ?>
        <?php endif; ?>
        <?php if (Yii::$app->user->can('notification-template.delete')) : ?>
            <form action="<?= \yii\helpers\Url::to(['delete', 'id' => $model->id]) ?>" method="post" style="display:inline"
                  onsubmit="return confirm('Delete this notification template?')">
                <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->csrfToken ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm ms-1">Delete</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($page->deliversAsInactive) : ?>
    <div class="alert alert-warning" data-testid="notification-template-inactive-identity">
        <?= Html::encode(sprintf(NotificationTemplatePage::DELIVERS_AS_INACTIVE, $page->deliversAsName())) ?>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <table class="table table-bordered">
            <tr>
                <th style="width:180px">Channel</th>
                <td><span class="badge text-bg-secondary"><?= Html::encode(NotificationTemplate::channelLabel($model->channel)) ?></span></td>
            </tr>
            <tr>
                <th>Events</th>
                <td>
                    <?php foreach ($model->getEventList() as $ev) : ?>
                        <span class="badge text-bg-info"><?= Html::encode($ev) ?></span>
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr>
                <th>Workflows</th>
                <td data-testid="notification-template-workflows">
                    <?php if (($model->workflow_scope ?? NotificationTemplate::WORKFLOW_SCOPE_ALL) === NotificationTemplate::WORKFLOW_SCOPE_ALL) : ?>
                        <?= Html::encode(NotificationTemplatePage::ALL_WORKFLOWS) ?>
                    <?php else : ?>
                        <?php foreach ($page->workflowTemplates as $workflowTemplateId => $workflowTemplateName) : ?>
                            <?= Html::a(Html::encode($workflowTemplateName), ['/workflow-template/view', 'id' => $workflowTemplateId], ['class' => 'badge text-bg-secondary text-decoration-none me-1']) ?>
                        <?php endforeach; ?>
                        <?php if ($page->workflowTemplates === [] && $page->hiddenWorkflowTemplates === 0) : ?>
                            <span class="text-muted"><?= Html::encode(NotificationTemplatePage::NO_WORKFLOWS) ?></span>
                        <?php endif; ?>
                        <?php if ($page->hiddenWorkflowTemplates > 0) : ?>
                            <div class="text-muted small" data-testid="notification-template-hidden-workflows">
                                <?= Html::encode(sprintf(NotificationTemplatePage::HIDDEN_WORKFLOWS, $page->hiddenWorkflowTemplates)) ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Level</th>
                <td data-testid="notification-template-level"><?= Html::encode(NotificationTemplate::LEVEL_LABELS[$model->level ?? NotificationTemplate::LEVEL_ALL] ?? (string)$model->level) ?></td>
            </tr>
            <tr>
                <th>Delivers as</th>
                <td data-testid="notification-template-delivers-as">
                    <?= Html::encode($page->deliversAsName()) ?>
                    <?php if ($page->deliversAsInactive) : ?>
                        <span class="badge text-bg-warning ms-1">inactive</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($model->channel === NotificationTemplate::CHANNEL_MATTERMOST) : ?>
            <tr>
                <th>Checked</th>
                <td data-testid="notification-template-check">
                    <?php if ($page->check === null) : ?>
                        <span class="text-muted"><?= Html::encode(NotificationTemplatePage::CHECK_NONE) ?></span>
                    <?php else : ?>
                        <?= Html::encode(sprintf(
                            NotificationTemplatePage::CHECK_KEPT,
                            date('Y-m-d H:i', $page->check['checked_at']),
                            $page->check['bot_username'],
                            $page->check['channel_id']
                        )) ?>
                        <?php if (!$page->check['current']) : ?>
                            <div class="text-muted small"><?= Html::encode(NotificationTemplatePage::CHECK_STALE) ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
            <?php if ($model->description) : ?>
            <tr>
                <th>Description</th>
                <td><?= Html::encode($model->description) ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <th>Subject Template</th>
                <td><code><?= Html::encode((string)$model->subject_template) ?></code></td>
            </tr>
            <tr>
                <th>Body Template</th>
                <td><pre class="mb-0" style="white-space:pre-wrap"><?= Html::encode((string)$model->body_template) ?></pre></td>
            </tr>
            <tr>
                <th>Config</th>
                <td>
                    <pre class="mb-0 font-monospace" style="white-space:pre-wrap" data-testid="notification-template-config"><?= Html::encode($model->getRedactedConfigJson()) ?></pre>
                    <?php if ($shownConfig !== $model->getParsedConfig()) : ?>
                        <div class="form-text" data-testid="notification-template-secret-note"><?= sprintf(Html::encode(NotificationConfigSecrets::PAGE_NOTE), Html::tag('code', Html::encode(SurveySecrets::PLACEHOLDER))) ?></div>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Created</th>
                <td><?= Html::encode(date('Y-m-d H:i', (int)$model->created_at)) ?> by <?= Html::encode($model->creator?->username ?? '—') ?></td>
            </tr>
        </table>
    </div>
</div>

<h4 class="mt-4">Deliveries</h4>
<?php if ($deliveries === []) : ?>
    <div class="text-muted mb-2" data-testid="notification-template-no-deliveries"><?= Html::encode(NotificationTemplatePage::NO_DELIVERIES) ?></div>
<?php else : ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover" id="notification-template-deliveries">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Event</th>
                    <th>Kind</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>About</th>
                    <th>Last error</th>
                    <th>Created</th>
                    <th>Finished</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($deliveries as $delivery) : ?>
                <tr data-delivery-id="<?= (int)$delivery->id ?>">
                    <td><?= (int)$delivery->id ?></td>
                    <td><code><?= Html::encode($delivery->event) ?></code></td>
                    <td class="text-nowrap"><?= Html::encode(NotificationTemplatePage::KIND_LABELS[$delivery->kind] ?? $delivery->kind) ?></td>
                    <td><span class="badge <?= Html::encode(NotificationTemplatePage::STATUS_BADGES[$delivery->status] ?? 'text-bg-secondary') ?>"><?= Html::encode($delivery->status) ?></span></td>
                    <td><?= (int)$delivery->attempts ?></td>
                    <td class="text-nowrap">
                        <?php foreach (NotificationTemplatePage::aboutOf($delivery) as [$label, $route]) : ?>
                            <?= Html::a(Html::encode($label), $route, ['class' => 'd-block']) ?>
                        <?php endforeach; ?>
                    </td>
                    <td class="small"><?= Html::encode((string)$delivery->last_error) ?></td>
                    <td class="text-muted small text-nowrap"><?= TimeHelper::relative((int)$delivery->created_at) ?></td>
                    <td class="text-muted small text-nowrap">
                        <?php if ($delivery->finished_at !== null) : ?>
                            <?= TimeHelper::relative((int)$delivery->finished_at) ?>
                        <?php elseif ($delivery->status === NotificationDelivery::STATUS_SENDING) : ?>
                            <?= sprintf(Html::encode(NotificationTemplatePage::SENDING_SINCE), TimeHelper::relative((int)$delivery->claimed_at)) ?>
                        <?php else : ?>
                            <?= sprintf(Html::encode(NotificationTemplatePage::NEXT_ATTEMPT), TimeHelper::relative((int)$delivery->next_attempt_at)) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    $pagination = $page->deliveries->getPagination();
    if ($pagination !== false && $pagination->pageCount > 1) {
        echo \yii\widgets\LinkPager::widget(['pagination' => $pagination, 'firstPageLabel' => '«', 'lastPageLabel' => '»']);
    }
    ?>
<?php endif; ?>
<?php if ($page->hiddenDeliveries > 0) : ?>
    <div class="text-muted small" data-testid="notification-template-hidden-deliveries">
        <?= Html::encode(sprintf(NotificationTemplatePage::HIDDEN_DELIVERIES, $page->hiddenDeliveries)) ?>
    </div>
<?php endif; ?>
