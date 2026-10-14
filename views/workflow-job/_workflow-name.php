<?php

declare(strict_types=1);

/**
 * The name of the workflow template that a run, an approval request or a
 * job belongs to. A live one links to its page when $link is set. A
 * deleted one, whose page answers 404, reads its name without a link and
 * Texts::WORKFLOW_DELETED after it, muted, as the jobs list marks a deleted
 * job template. Without a name (its row is gone): PollPayload::NO_VALUE.
 * Used by the run page and the run list, the approval pages' For cell and
 * the dashboard.
 *
 * @var yii\web\View $this
 * @var string|null $name the workflow template's name
 * @var int $id the workflow template's ID
 * @var bool $deleted whether the workflow template was deleted
 * @var bool $link whether a live workflow template links to its page
 * @var array<string, mixed>|null $linkOptions the link's attributes
 */

use app\services\runstate\PollPayload;
use app\services\runstate\Texts;
use yii\helpers\Html;

if ($name === null) {
    echo Html::encode(PollPayload::NO_VALUE);
} elseif ($deleted) {
    echo Html::encode($name) . ' ' . Html::tag('span', Html::encode(Texts::WORKFLOW_DELETED), ['class' => 'text-muted']);
} elseif ($link) {
    echo Html::a(Html::encode($name), ['/workflow-template/view', 'id' => $id], $linkOptions ?? []);
} else {
    echo Html::encode($name);
}
