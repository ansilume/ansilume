<?php

declare(strict_types=1);

/**
 * Password reset email — HTML version.
 *
 * @var yii\web\View $this
 * @var app\models\User $user
 * @var string $resetUrl
 * @var int $expireMinutes
 */

$username = htmlspecialchars($user->username, ENT_QUOTES, 'UTF-8');
$safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

$this->params['previewText'] = 'Reset your Ansilume password';
?>
<!-- Heading -->
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
    <tr>
        <td style="font-size:24px; font-weight:700; color:#ffffff; padding-bottom:8px; font-family:Arial, Helvetica, sans-serif;">
            Password Reset
        </td>
    </tr>
    <tr>
        <td style="font-size:14px; color:#adb5bd; padding-bottom:24px; line-height:22px; font-family:Arial, Helvetica, sans-serif;">
            Hi <strong style="color:#e0e0e0;"><?= $username ?></strong>, we received a request to reset your password.
        </td>
    </tr>
</table>

<!-- CTA Button -->
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
    <tr>
        <td align="center" style="padding:8px 0 28px;">
            <!--[if mso]>
            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                href="<?= $safeUrl ?>" style="height:48px;v-text-anchor:middle;width:260px;" arcsize="10%" strokecolor="#3b82f6" fillcolor="#3b82f6">
                <w:anchorlock/>
                <center style="color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;">Reset Password</center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-->
            <a href="<?= $safeUrl ?>" class="btn-primary" style="display:inline-block; background-color:#3b82f6; color:#ffffff; font-size:15px; font-weight:700; text-decoration:none; padding:14px 48px; border-radius:6px; font-family:Arial, Helvetica, sans-serif; mso-hide:all;">
                Reset Password
            </a>
            <!--<![endif]-->
        </td>
    </tr>
</table>

<!-- Details -->
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="border-top:1px solid rgba(255,255,255,0.08); padding-top:20px;">
    <tr>
        <td style="font-size:13px; color:#6c757d; line-height:20px; padding-top:20px; font-family:Arial, Helvetica, sans-serif;">
            This link expires in <strong style="color:#adb5bd;"><?= $expireMinutes ?> minutes</strong>.<br />
            If you did not request a password reset, you can safely ignore this email.
        </td>
    </tr>
    <tr>
        <td style="font-size:12px; color:#495057; padding-top:16px; line-height:18px; word-break:break-all; font-family:Arial, Helvetica, sans-serif;">
            If the button does not work, copy and paste this URL:<br />
            <a href="<?= $safeUrl ?>" style="color:#4a9eff; text-decoration:underline; font-size:12px;"><?= $safeUrl ?></a>
        </td>
    </tr>
</table>
