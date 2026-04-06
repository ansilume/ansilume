<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\PasswordResetRequestForm;
use app\models\User;
use app\tests\integration\DbTestCase;

/**
 * Exercises PasswordResetRequestForm end-to-end, including the User lookup,
 * token issuance, URL building, and mailer dispatch. Uses Yii's file-backed
 * mailer in "useFileTransport" mode so no email actually leaves the box —
 * the send() call returns true and we can assert on the persisted token.
 */
class PasswordResetRequestFormTest extends DbTestCase
{
    /** @var mixed */
    private $originalMailer;
    /** @var mixed */
    private $originalUrlManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Console UrlManager can't build URLs — install a web UrlManager
        // with a known host + scriptUrl so buildResetUrl() works.
        $components = \Yii::$app->getComponents(true);
        $this->originalUrlManager = $components['urlManager'] ?? null;
        \Yii::$app->set('urlManager', new \yii\web\UrlManager([
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'scriptUrl' => '/index.php',
            'baseUrl' => '',
            'hostInfo' => 'https://ansilume.test',
        ]));

        // Swap the mailer with a test double so send() returns true without
        // touching SMTP. Avoid calling has() + get() because the default
        // swiftmailer class may not be installed; snapshot via getComponents().
        $this->originalMailer = $components['mailer'] ?? null;
        \Yii::$app->set('mailer', new class extends \yii\base\Component implements \yii\mail\MailerInterface {
            public int $sendCount = 0;
            public bool $shouldReturn = true;
            /**
             * @param string|array<string, string>|null $view
             * @param array<string, mixed> $params
             */
            public function compose($view = null, array $params = []): \yii\mail\MessageInterface
            {
                $mailer = $this;
                return new class ($mailer) extends \yii\base\BaseObject implements \yii\mail\MessageInterface {
                    /** @var object */
                    public object $mailer;
                    public function __construct(object $mailer)
                    {
                        parent::__construct();
                        $this->mailer = $mailer;
                    }
                    public function getCharset(): string { return 'utf-8'; }
                    public function setCharset($charset): self { return $this; }
                    /** @return string */
                    public function getFrom() { return ''; }
                    /** @param string|array<string, string> $from */
                    public function setFrom($from): self { return $this; }
                    /** @return string */
                    public function getTo() { return ''; }
                    /** @param string|array<string, string> $to */
                    public function setTo($to): self { return $this; }
                    /** @return string */
                    public function getReplyTo() { return ''; }
                    /** @param string|array<string, string> $replyTo */
                    public function setReplyTo($replyTo): self { return $this; }
                    /** @return string */
                    public function getCc() { return ''; }
                    /** @param string|array<string, string> $cc */
                    public function setCc($cc): self { return $this; }
                    /** @return string */
                    public function getBcc() { return ''; }
                    /** @param string|array<string, string> $bcc */
                    public function setBcc($bcc): self { return $this; }
                    public function getSubject() { return ''; }
                    public function setSubject($subject): self { return $this; }
                    public function setTextBody($text): self { return $this; }
                    public function setHtmlBody($html): self { return $this; }
                    /** @param array<string, string> $options */
                    public function attach($fileName, array $options = []): self { return $this; }
                    /** @param array<string, string> $options */
                    public function attachContent($content, array $options = []): self { return $this; }
                    /** @param array<string, string> $options */
                    public function embed($fileName, array $options = []): string { return ''; }
                    /** @param array<string, string> $options */
                    public function embedContent($content, array $options = []): string { return ''; }
                    public function send(\yii\mail\MailerInterface $mailer = null): bool
                    {
                        $m = $this->mailer;
                        /** @phpstan-ignore-next-line */
                        $m->sendCount++;
                        /** @phpstan-ignore-next-line */
                        return $m->shouldReturn;
                    }
                    public function toString(): string { return ''; }
                };
            }
            public function send($message): bool { return true; }
            /** @param array<int, \yii\mail\MessageInterface> $messages */
            public function sendMultiple(array $messages): int { return count($messages); }
        });
    }

    protected function tearDown(): void
    {
        \Yii::$app->set('mailer', $this->originalMailer);
        \Yii::$app->set('urlManager', $this->originalUrlManager);
        parent::tearDown();
    }

    public function testValidationRequiresEmail(): void
    {
        $form = new PasswordResetRequestForm();
        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('email', $form->errors);
    }

    public function testValidationRejectsInvalidEmail(): void
    {
        $form = new PasswordResetRequestForm();
        $form->email = 'not-an-email';
        $this->assertFalse($form->validate());
        $this->assertArrayHasKey('email', $form->errors);
    }

    public function testValidationAcceptsWellFormedEmail(): void
    {
        $form = new PasswordResetRequestForm();
        $form->email = 'user@example.com';
        $this->assertTrue($form->validate());
    }

    public function testSendReturnsTrueForUnknownEmail(): void
    {
        $form = new PasswordResetRequestForm();
        $form->email = 'nobody-' . uniqid() . '@example.com';

        // Always returns true to prevent email enumeration, and no mail
        // gets dispatched.
        $this->assertTrue($form->sendResetEmail());
        /** @var object{sendCount: int} $mailer */
        $mailer = \Yii::$app->get('mailer');
        $this->assertSame(0, $mailer->sendCount);
    }

    public function testSendGeneratesTokenForExistingUser(): void
    {
        $user = $this->createUser();
        $this->assertEmpty($user->password_reset_token);

        $form = new PasswordResetRequestForm();
        $form->email = $user->email;
        $this->assertTrue($form->sendResetEmail());

        $reloaded = User::findOne($user->id);
        $this->assertNotNull($reloaded);
        $this->assertNotEmpty($reloaded->password_reset_token);

        /** @var object{sendCount: int} $mailer */
        $mailer = \Yii::$app->get('mailer');
        $this->assertSame(1, $mailer->sendCount);
    }

    public function testSendReusesStillValidToken(): void
    {
        $user = $this->createUser();
        $user->generatePasswordResetToken();
        $user->save(false);
        $originalToken = $user->password_reset_token;

        $form = new PasswordResetRequestForm();
        $form->email = $user->email;
        $this->assertTrue($form->sendResetEmail());

        $reloaded = User::findOne($user->id);
        $this->assertNotNull($reloaded);
        // The still-valid token is reused, not rotated.
        $this->assertSame($originalToken, $reloaded->password_reset_token);
    }

    public function testSendIgnoresInactiveUser(): void
    {
        $user = $this->createUser();
        $user->status = User::STATUS_INACTIVE;
        $user->save(false);

        $form = new PasswordResetRequestForm();
        $form->email = $user->email;
        $this->assertTrue($form->sendResetEmail());

        /** @var object{sendCount: int} $mailer */
        $mailer = \Yii::$app->get('mailer');
        $this->assertSame(0, $mailer->sendCount);
    }

    public function testSendHandlesMailerException(): void
    {
        // Swap in a mailer that throws on compose — sendResetEmail must
        // still return true (silently log the failure).
        \Yii::$app->set('mailer', new class extends \yii\base\Component implements \yii\mail\MailerInterface {
            /**
             * @param string|array<string, string>|null $view
             * @param array<string, mixed> $params
             */
            public function compose($view = null, array $params = []): \yii\mail\MessageInterface
            {
                throw new \RuntimeException('simulated mailer failure');
            }
            public function send($message): bool { return false; }
            /** @param array<int, \yii\mail\MessageInterface> $messages */
            public function sendMultiple(array $messages): int { return 0; }
        });

        $user = $this->createUser();

        $form = new PasswordResetRequestForm();
        $form->email = $user->email;
        // The method swallows the exception internally via try/catch.
        $this->assertTrue($form->sendResetEmail());
    }
}
