<?php

declare(strict_types=1);

/**
 * Job success notification — HTML version.
 *
 * @var yii\web\View $this
 * @var app\models\Job $job
 * @var string $jobUrl
 */

$template = $job->jobTemplate;
$launcher = $job->launcher;
$started = $job->started_at ? date('Y-m-d H:i:s T', $job->started_at) : '—';
$finished = $job->finished_at ? date('Y-m-d H:i:s T', $job->finished_at) : '—';

// Duration
$duration = '—';
if ($job->started_at && $job->finished_at) {
    $secs = $job->finished_at - $job->started_at;
    if ($secs < 60) {
        $duration = $secs . 's';
    } elseif ($secs < 3600) {
        $duration = sprintf('%dm %ds', intdiv($secs, 60), $secs % 60);
    } else {
        $duration = sprintf('%dh %dm', intdiv($secs, 3600), intdiv($secs % 3600, 60));
    }
}

$safeUrl = htmlspecialchars($jobUrl, ENT_QUOTES, 'UTF-8');

$this->params['previewText'] = sprintf('Job #%d succeeded — %s', $job->id, $template->name ?? 'unknown');
?>
<!-- Status badge -->
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
    <tr>
        <td style="padding-bottom:20px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td style="background-color:#198754; color:#ffffff; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; padding:6px 14px; border-radius:4px; font-family:Arial, Helvetica, sans-serif;">
                        Succeeded
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td style="font-size:22px; font-weight:700; color:#ffffff; padding-bottom:6px; font-family:Arial, Helvetica, sans-serif;">
            Job #<?= (int)$job->id ?>
        </td>
    </tr>
    <tr>
        <td style="font-size:14px; color:#adb5bd; padding-bottom:24px; font-family:Arial, Helvetica, sans-serif;">
            <?= htmlspecialchars($template->name ?? 'Unknown template', ENT_QUOTES, 'UTF-8') ?>
        </td>
    </tr>
</table>

<!-- Details table -->
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#13161b; border-radius:6px; border:1px solid rgba(255,255,255,0.06);">
    <tr>
        <td style="padding:6px 20px 6px; border-bottom:1px solid rgba(255,255,255,0.06);">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                <tr>
                    <td width="120" style="font-size:12px; color:#6c757d; padding:10px 0; font-family:Arial, Helvetica, sans-serif;">Playbook</td>
                    <td style="font-size:13px; color:#e0e0e0; padding:10px 0; font-family:'Courier New', Courier, monospace;"><?= htmlspecialchars($template->playbook ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td style="padding:0 20px; border-bottom:1px solid rgba(255,255,255,0.06);">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                <tr>
                    <td width="120" style="font-size:12px; color:#6c757d; padding:10px 0; font-family:Arial, Helvetica, sans-serif;">Launched by</td>
                    <td style="font-size:13px; color:#e0e0e0; padding:10px 0; font-family:Arial, Helvetica, sans-serif;"><?= htmlspecialchars($launcher->username ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td style="padding:0 20px; border-bottom:1px solid rgba(255,255,255,0.06);">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                <tr>
                    <td width="120" style="font-size:12px; color:#6c757d; padding:10px 0; font-family:Arial, Helvetica, sans-serif;">Started</td>
                    <td style="font-size:13px; color:#e0e0e0; padding:10px 0; font-family:Arial, Helvetica, sans-serif;"><?= htmlspecialchars($started, ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td style="padding:0 20px; border-bottom:1px solid rgba(255,255,255,0.06);">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                <tr>
                    <td width="120" style="font-size:12px; color:#6c757d; padding:10px 0; font-family:Arial, Helvetica, sans-serif;">Finished</td>
                    <td style="font-size:13px; color:#e0e0e0; padding:10px 0; font-family:Arial, Helvetica, sans-serif;"><?= htmlspecialchars($finished, ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td style="padding:0 20px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                <tr>
                    <td width="120" style="font-size:12px; color:#6c757d; padding:10px 0; font-family:Arial, Helvetica, sans-serif;">Duration</td>
                    <td style="font-size:13px; color:#75d9a0; font-weight:700; padding:10px 0; font-family:Arial, Helvetica, sans-serif;"><?= htmlspecialchars($duration, ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<!-- CTA Button -->
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
    <tr>
        <td align="center" style="padding:28px 0 8px;">
            <!--[if mso]>
            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                href="<?= $safeUrl ?>" style="height:44px;v-text-anchor:middle;width:220px;" arcsize="10%" strokecolor="#3b82f6" fillcolor="#3b82f6">
                <w:anchorlock/>
                <center style="color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;">View Job Details</center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-->
            <a href="<?= $safeUrl ?>" class="btn-primary" style="display:inline-block; background-color:#3b82f6; color:#ffffff; font-size:14px; font-weight:700; text-decoration:none; padding:12px 36px; border-radius:6px; font-family:Arial, Helvetica, sans-serif; mso-hide:all;">
                View Job Details
            </a>
            <!--<![endif]-->
        </td>
    </tr>
</table>
