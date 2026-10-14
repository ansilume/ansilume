<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array<string, mixed>|null $banner runstate\Banner::of(): what the run waits for; null when it waits for nothing */
// web/js/workflow-run-live.js builds the same markup from the poll's banner.

use yii\helpers\Html;

// A fact: the request (a link only for a viewer who may open it), another
// fact with its address, or plain text.
$renderFact = static function (array $fact): string {
    $text = Html::encode((string)$fact['text']);
    $options = isset($fact['request']) ? ['data-banner-request' => true] : [];
    if (isset($fact['href'])) {
        return Html::a($text, (string)$fact['href'], $options);
    }

    return $options === [] ? $text : Html::tag('span', $text, $options);
};
?>
<?php if ($banner === null) : ?>
<div id="wj-wait-banner" class="alert" role="status" hidden></div>
<?php else :
    /** @var list<array<string, mixed>> $facts */
    $facts = $banner['facts'];
    /** @var array{text: string, action?: array{text: string, href: string}}|null $personal */
    $personal = $banner['personal'];
    ?>
<div id="wj-wait-banner" class="alert alert-<?= Html::encode((string)$banner['tone']) ?>" role="status" data-wait="<?= Html::encode((string)$banner['kind']) ?>">
    <div class="fw-semibold" data-banner-title><?= Html::encode((string)$banner['title']) ?></div>
    <?php if ($facts !== []) : ?>
    <ul class="small mt-1 mb-0" data-banner-facts>
        <?php foreach ($facts as $fact) : ?>
        <li><?= $renderFact($fact) // xss-ok: built with Html::encode() and Html::a()?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($personal !== null) : ?>
    <div class="mt-2">
        <span data-banner-personal><?= Html::encode($personal['text']) ?></span>
        <?php if (isset($personal['action'])) : ?>
            <?= Html::a(Html::encode($personal['action']['text']), $personal['action']['href'], ['id' => 'wj-review-link', 'class' => 'btn btn-sm btn-primary ms-2']) ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($banner['warning'] !== null) : ?>
    <div class="mt-2 fw-semibold" data-banner-warning><?= Html::encode((string)$banner['warning']) ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>
