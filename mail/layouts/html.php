<?php

declare(strict_types=1);

/**
 * Base HTML email layout for Ansilume.
 *
 * Table-based layout for maximum email client compatibility:
 * Thunderbird, Outlook (2007–2024), Gmail, Apple Mail, Yahoo Mail.
 *
 * @var yii\web\View $this
 * @var string $content  The inner template content
 */

$appName = Yii::$app->name ?? 'Ansilume';
$year = date('Y');
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="x-apple-disable-message-reformatting" />
    <meta name="color-scheme" content="dark" />
    <meta name="supported-color-schemes" content="dark" />
    <title><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <style>
        table { border-collapse: collapse; }
        td { font-family: Arial, Helvetica, sans-serif; }
    </style>
    <![endif]-->
    <style type="text/css">
        /* Reset */
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0; padding: 0; width: 100% !important; height: 100% !important; }

        /* Dark mode */
        body { background-color: #0f1117; color: #e0e0e0; }

        /* Responsive */
        @media only screen and (max-width: 620px) {
            .email-container { width: 100% !important; max-width: 100% !important; }
            .stack-column { display: block !important; width: 100% !important; }
            .stack-column-center { text-align: center !important; }
            .content-padding { padding-left: 20px !important; padding-right: 20px !important; }
        }

        /* Button hover (clients that support it) */
        .btn-primary:hover { background-color: #4a8eff !important; }

        /* Gmail dark mode fix */
        u + .body .email-container { min-width: 100vw; }

        /* Link color */
        a { color: #4a9eff; }
        a:visited { color: #4a9eff; }
    </style>
</head>
<body class="body" style="margin:0; padding:0; background-color:#0f1117; color:#e0e0e0; font-family:Arial, Helvetica, sans-serif;">

    <!-- Preview text (hidden) -->
    <!--[if !mso]><!-->
    <div style="display:none; font-size:1px; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden; mso-hide:all;">
        <?= isset($previewText) ? htmlspecialchars($previewText, ENT_QUOTES, 'UTF-8') : '' ?>
    </div>
    <!--<![endif]-->

    <!-- Full-width background wrapper -->
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#0f1117;">
        <tr>
            <td align="center" style="padding:20px 10px;">

                <!-- Email container: 600px max -->
                <!--[if mso]>
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" align="center"><tr><td>
                <![endif]-->
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" class="email-container" style="max-width:600px; width:100%; margin:0 auto; border-radius:8px; overflow:hidden;">

                    <!-- ====== HEADER ====== -->
                    <tr>
                        <td style="background-color:#13161b; padding:28px 40px 24px; border-bottom:1px solid rgba(255,255,255,0.08);" class="content-padding">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td style="font-size:22px; font-weight:700; color:#ffffff; letter-spacing:0.02em; font-family:Arial, Helvetica, sans-serif;">
                                        <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- ====== BODY ====== -->
                    <tr>
                        <td style="background-color:#1a1d24; padding:36px 40px;" class="content-padding">
                            <?= $content ?>
                        </td>
                    </tr>

                    <!-- ====== FOOTER ====== -->
                    <tr>
                        <td style="background-color:#13161b; padding:24px 40px; border-top:1px solid rgba(255,255,255,0.08);" class="content-padding">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td style="font-size:12px; color:#6c757d; line-height:18px; font-family:Arial, Helvetica, sans-serif;">
                                        This is an automated message from <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>.<br />
                                        Please do not reply to this email.
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-size:11px; color:#495057; padding-top:12px; font-family:Arial, Helvetica, sans-serif;">
                                        &copy; <?= $year ?> <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                </table>
                <!--[if mso]>
                </td></tr></table>
                <![endif]-->

            </td>
        </tr>
    </table>

</body>
</html>
