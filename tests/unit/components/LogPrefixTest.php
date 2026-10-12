<?php

declare(strict_types=1);

namespace app\tests\unit\components;

use app\components\LogPrefix;
use PHPUnit\Framework\TestCase;
use yii\base\Application;
use yii\web\IdentityInterface;
use yii\web\Request;
use yii\web\Session;
use yii\web\User;

/**
 * LogPrefix: web log lines start with "[client IP][user ID]" and never with
 * the session ID.
 */
class LogPrefixTest extends TestCase
{
    private const MESSAGE = ['Not found.', 2, 'yii\web\HttpException:404', 0.0];

    /** @var array<string, mixed> */
    private array $savedComponents = [];

    /** @var array<string, mixed> */
    private array $savedServer = [];

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        foreach ($this->savedComponents as $id => $definition) {
            \Yii::$app->set($id, $definition);
        }
        $this->savedComponents = [];
    }

    public function testALoggedInUsersRequestNamesTheClientAndTheUser(): void
    {
        $this->webRequest('198.51.100.20');
        $this->install('user', $this->user(42));

        $this->assertSame('[198.51.100.20][42]', LogPrefix::ipAndUser(self::MESSAGE));
    }

    public function testAStringUserIdIsUsedAsItIs(): void
    {
        $this->webRequest('198.51.100.20');
        $this->install('user', $this->user('api-7'));

        $this->assertSame('[198.51.100.20][api-7]', LogPrefix::ipAndUser(self::MESSAGE));
    }

    public function testTheSessionIdIsNeverPartOfThePrefix(): void
    {
        $this->webRequest('198.51.100.20');
        $this->install('user', $this->user(42));
        $session = new class () extends Session {
            public function getIsActive()
            {
                return true;
            }

            public function getId()
            {
                return 'activesessionid0123456789abcdef';
            }

            public function close()
            {
            }
        };
        $this->install('session', $session);

        $prefix = LogPrefix::ipAndUser(self::MESSAGE);

        $this->assertSame('[198.51.100.20][42]', $prefix);
        $this->assertStringNotContainsString('activesessionid', $prefix);
    }

    public function testAGuestIsShownAsADash(): void
    {
        $this->webRequest('198.51.100.20');
        $this->install('user', $this->user(null));

        $this->assertSame('[198.51.100.20][-]', LogPrefix::ipAndUser(self::MESSAGE));
    }

    /**
     * Like Yii's default prefix, it does not create the user component (that
     * could start a session) when the request has not used it.
     */
    public function testAUserComponentTheRequestDidNotCreateIsNotCreated(): void
    {
        $this->webRequest('198.51.100.20');
        $this->define('user', ['class' => User::class, 'identityClass' => \app\models\User::class, 'enableSession' => false]);

        $this->assertSame('[198.51.100.20][-]', LogPrefix::ipAndUser(self::MESSAGE));
        $app = \Yii::$app;
        $this->assertInstanceOf(Application::class, $app);
        $this->assertFalse($app->has('user', true));
    }

    public function testAnotherUserComponentIsShownAsADash(): void
    {
        $this->webRequest('198.51.100.20');
        $this->install('user', new \stdClass());

        $this->assertSame('[198.51.100.20][-]', LogPrefix::ipAndUser(self::MESSAGE));
    }

    public function testAWebRequestWithoutAClientAddressShowsADash(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        $this->install('request', new Request(['cookieValidationKey' => 'test-key']));
        $this->install('user', $this->user(42));

        $this->assertSame('[-][42]', LogPrefix::ipAndUser(self::MESSAGE));
    }

    /**
     * Console commands have no client and, here, no user component.
     */
    public function testAConsoleCommandHasNeitherClientNorUser(): void
    {
        $this->define('user', null);

        $this->assertSame('[-][-]', LogPrefix::ipAndUser(self::MESSAGE));
    }

    /**
     * Messages flushed after the application ended get no prefix.
     */
    public function testWithoutAnApplicationThereIsNoPrefix(): void
    {
        $app = \Yii::$app;
        \Yii::$app = null;
        try {
            $this->assertSame('', LogPrefix::ipAndUser(self::MESSAGE));
        } finally {
            \Yii::$app = $app;
        }
    }

    private function webRequest(string $ip): void
    {
        $_SERVER['REMOTE_ADDR'] = $ip;
        $this->install('request', new Request(['cookieValidationKey' => 'test-key']));
    }

    /**
     * A user component, logged in as $id (null: a guest).
     */
    private function user(int|string|null $id): User
    {
        $user = new User(['identityClass' => \app\models\User::class, 'enableSession' => false, 'enableAutoLogin' => false]);
        $user->setIdentity($id === null ? null : $this->identity($id));

        return $user;
    }

    /**
     * Installs $component as $id and instantiates it, as a request that used
     * it would have.
     */
    private function install(string $id, object $component): void
    {
        $this->define($id, $component);
        $app = \Yii::$app;
        $this->assertInstanceOf(Application::class, $app);
        $this->assertSame($component, $app->get($id));
    }

    /**
     * Sets the definition of $id (null removes it); tearDown() restores it.
     *
     * @param array<string, mixed>|object|null $definition
     */
    private function define(string $id, array|object|null $definition): void
    {
        $app = \Yii::$app;
        $this->assertInstanceOf(Application::class, $app);
        if (!array_key_exists($id, $this->savedComponents)) {
            $this->savedComponents[$id] = $app->getComponents(true)[$id] ?? null;
        }
        $app->set($id, $definition);
    }

    private function identity(int|string $id): IdentityInterface
    {
        return new class ($id) implements IdentityInterface {
            public function __construct(private int|string $id)
            {
            }

            public static function findIdentity($id)
            {
                return null;
            }

            public static function findIdentityByAccessToken($token, $type = null)
            {
                return null;
            }

            public function getId()
            {
                return $this->id;
            }

            public function getAuthKey()
            {
                return null;
            }

            public function validateAuthKey($authKey)
            {
                return false;
            }
        };
    }
}
