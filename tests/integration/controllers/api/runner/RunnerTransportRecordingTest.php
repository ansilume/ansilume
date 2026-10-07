<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\runner;

use app\components\RunnerTransportClassifier;
use app\controllers\api\runner\JobsController;
use app\controllers\api\runner\RegisterController;
use app\models\Runner;
use app\models\RunnerGroup;
use app\tests\integration\controllers\WebControllerTestCase;
use yii\web\UnauthorizedHttpException;

/**
 * Every runner API request records how the runner reached the server
 * (transport, remote_addr) and stamps plaintext_seen_at whenever it came over
 * plain HTTP from outside the trusted networks. Claim responses carry
 * decrypted credentials, so operators must see which runners received them
 * in clear. The classification reads $_SERVER, which each test sets for its
 * request and tearDown() restores.
 */
class RunnerTransportRecordingTest extends WebControllerTestCase
{
    private const BOOTSTRAP_SECRET = 'transport-test-bootstrap-secret';

    /** Every $_SERVER key the classifier reads. */
    private const TRANSPORT_KEYS = [
        'REMOTE_ADDR',
        'HTTPS',
        'HTTP_X_FORWARDED_PROTO',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'HTTP_X_FORWARDED_SSL',
        'HTTP_FRONT_END_HTTPS',
    ];

    /** @var array<array-key, mixed> */
    private array $serverBackup = [];
    private bool $hadTrustedNetworks = false;
    private mixed $trustedNetworksBackup = null;
    private mixed $bootstrapSecretBackup = null;
    private RunnerGroup $group;
    private Runner $runner;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        parent::setUp();
        $this->hadTrustedNetworks = array_key_exists('runnerTrustedNetworks', \Yii::$app->params);
        $this->trustedNetworksBackup = \Yii::$app->params['runnerTrustedNetworks'] ?? null;
        \Yii::$app->params['runnerTrustedNetworks'] = '';
        $this->bootstrapSecretBackup = $_ENV['RUNNER_BOOTSTRAP_SECRET'] ?? null;
        $this->arriveFrom([]);

        $userId = (int)$this->createUser('runner-transport')->id;
        $this->group = $this->createRunnerGroup($userId);
        $this->runner = $this->createRunner((int)$this->group->id, $userId);
        $token = Runner::generateToken();
        $this->runner->token_hash = $token['hash'];
        $this->runner->save(false);
        \Yii::$app->request->headers->set('Authorization', 'Bearer ' . $token['raw']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        if ($this->hadTrustedNetworks) {
            \Yii::$app->params['runnerTrustedNetworks'] = $this->trustedNetworksBackup;
        } else {
            unset(\Yii::$app->params['runnerTrustedNetworks']);
        }
        if ($this->bootstrapSecretBackup === null) {
            unset($_ENV['RUNNER_BOOTSTRAP_SECRET']);
        } else {
            $_ENV['RUNNER_BOOTSTRAP_SECRET'] = $this->bootstrapSecretBackup;
        }
        parent::tearDown();
    }

    // -- Authenticated runner requests --------------------------------------------

    public function testPlainHttpFromTheDockerNetworkIsRecordedAsInternal(): void
    {
        $this->arriveFrom(['REMOTE_ADDR' => '172.18.0.5']);

        $this->assertTrue($this->heartbeat()['ok']);

        $runner = $this->reloaded();
        $this->assertSame(RunnerTransportClassifier::HTTP_INTERNAL, $runner->transport);
        $this->assertSame('172.18.0.5', $runner->remote_addr);
        $this->assertNull($runner->plaintext_seen_at);
        $this->assertFalse($runner->hasInsecureTransport());
    }

    public function testHttpsIsRecordedWithThePeerAddress(): void
    {
        $this->arriveFrom(['REMOTE_ADDR' => '203.0.113.10', 'HTTPS' => 'on']);

        $this->heartbeat();

        $runner = $this->reloaded();
        $this->assertSame(RunnerTransportClassifier::HTTPS, $runner->transport);
        $this->assertSame('203.0.113.10', $runner->remote_addr);
        $this->assertNull($runner->plaintext_seen_at);
    }

    public function testPlainHttpFromOutsideIsRecordedAndStampedButStillServed(): void
    {
        $this->arriveFrom(['REMOTE_ADDR' => '203.0.113.10']);
        $before = time();

        $result = $this->heartbeat();

        $this->assertTrue($result['ok'], 'visibility only: the request is not rejected');
        $runner = $this->reloaded();
        $this->assertSame(RunnerTransportClassifier::HTTP_EXTERNAL, $runner->transport);
        $this->assertSame('203.0.113.10', $runner->remote_addr);
        $this->assertNotNull($runner->plaintext_seen_at);
        $this->assertGreaterThanOrEqual($before, $runner->plaintext_seen_at);
        $this->assertLessThanOrEqual(time(), $runner->plaintext_seen_at);
        $this->assertTrue($runner->hasInsecureTransport());
    }

    public function testATrustedProxyTerminatingTlsRecordsHttpsAndTheRealClient(): void
    {
        $this->arriveFrom([
            'REMOTE_ADDR' => '172.18.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ]);

        $this->heartbeat();

        $runner = $this->reloaded();
        $this->assertSame(RunnerTransportClassifier::HTTPS, $runner->transport);
        $this->assertSame('198.51.100.7', $runner->remote_addr);
        $this->assertNull($runner->plaintext_seen_at);
    }

    /**
     * Security: a remote runner must not be able to hide the plain HTTP
     * warning by sending proxy headers itself.
     */
    public function testForwardedHeadersFromAPublicPeerDoNotHideThePlainHttpWarning(): void
    {
        $this->arriveFrom([
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.4',
        ]);

        $this->heartbeat();

        $runner = $this->reloaded();
        $this->assertSame(RunnerTransportClassifier::HTTP_EXTERNAL, $runner->transport);
        $this->assertSame('203.0.113.10', $runner->remote_addr);
        $this->assertNotNull($runner->plaintext_seen_at);
    }

    public function testTheClaimRequestRecordsTheTransportToo(): void
    {
        $this->arriveFrom(['REMOTE_ADDR' => '203.0.113.10']);

        $result = (new JobsController('runner-api', \Yii::$app))->runAction('claim');

        $this->assertSame([], $result);
        $this->assertSame(204, \Yii::$app->response->statusCode);
        $runner = $this->reloaded();
        $this->assertSame(RunnerTransportClassifier::HTTP_EXTERNAL, $runner->transport);
        $this->assertNotNull($runner->plaintext_seen_at);
    }

    public function testRepeatedPlainHttpFromOutsideRefreshesPlaintextSeenAt(): void
    {
        $this->storeTransport(RunnerTransportClassifier::HTTP_EXTERNAL, '203.0.113.10', time() - 3600);
        $this->arriveFrom(['REMOTE_ADDR' => '203.0.113.10']);
        $before = time();

        $this->heartbeat();

        $runner = $this->reloaded();
        $this->assertSame(RunnerTransportClassifier::HTTP_EXTERNAL, $runner->transport);
        $this->assertSame('203.0.113.10', $runner->remote_addr);
        $this->assertGreaterThanOrEqual($before, $runner->plaintext_seen_at);
    }

    public function testUnchangedInternalRequestsLeaveTheRecordedColumnsAlone(): void
    {
        $earlierPlaintext = time() - 86400;
        $this->storeTransport(RunnerTransportClassifier::HTTP_INTERNAL, '172.18.0.5', $earlierPlaintext);
        $this->arriveFrom(['REMOTE_ADDR' => '172.18.0.5']);

        $this->assertSame([], $this->reloaded()->transportChanges($_SERVER, time()), 'nothing to write');
        $this->heartbeat();

        $runner = $this->reloaded();
        $this->assertSame(RunnerTransportClassifier::HTTP_INTERNAL, $runner->transport);
        $this->assertSame('172.18.0.5', $runner->remote_addr);
        $this->assertSame($earlierPlaintext, $runner->plaintext_seen_at, 'neither bumped nor cleared');
    }

    public function testAnInternalRunnerChangingAddressKeepsItsPlaintextHistory(): void
    {
        $earlierPlaintext = time() - 86400;
        $this->storeTransport(RunnerTransportClassifier::HTTP_INTERNAL, '172.18.0.5', $earlierPlaintext);
        $this->arriveFrom(['REMOTE_ADDR' => '172.18.0.9']);

        $this->heartbeat();

        $runner = $this->reloaded();
        $this->assertSame(RunnerTransportClassifier::HTTP_INTERNAL, $runner->transport);
        $this->assertSame('172.18.0.9', $runner->remote_addr);
        $this->assertSame($earlierPlaintext, $runner->plaintext_seen_at);
    }

    /**
     * After the operator fixes the transport, plaintext_seen_at stays so they
     * still know which runner received credentials in clear.
     */
    public function testMovingToHttpsKeepsThePlaintextHistory(): void
    {
        $earlierPlaintext = time() - 600;
        $this->storeTransport(RunnerTransportClassifier::HTTP_EXTERNAL, '203.0.113.10', $earlierPlaintext);
        $this->arriveFrom(['REMOTE_ADDR' => '203.0.113.10', 'HTTPS' => 'on']);

        $this->heartbeat();

        $runner = $this->reloaded();
        $this->assertSame(RunnerTransportClassifier::HTTPS, $runner->transport);
        $this->assertSame('203.0.113.10', $runner->remote_addr);
        $this->assertSame($earlierPlaintext, $runner->plaintext_seen_at);
        $this->assertFalse($runner->hasInsecureTransport());
    }

    public function testARequestWithoutAPeerAddressKeepsTheLastKnownTransport(): void
    {
        $earlierPlaintext = time() - 600;
        $this->storeTransport(RunnerTransportClassifier::HTTP_EXTERNAL, '203.0.113.10', $earlierPlaintext);
        $before = time();

        $this->heartbeat();

        $runner = $this->reloaded();
        $this->assertSame(RunnerTransportClassifier::HTTP_EXTERNAL, $runner->transport);
        $this->assertSame('203.0.113.10', $runner->remote_addr);
        $this->assertSame($earlierPlaintext, $runner->plaintext_seen_at);
        $this->assertGreaterThanOrEqual($before, $runner->last_seen_at, 'the heartbeat itself is still recorded');
    }

    public function testTheSoftwareVersionAndTheTransportAreRecordedTogether(): void
    {
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams(['software_version' => '2.6.1']);
        $this->arriveFrom(['REMOTE_ADDR' => '203.0.113.10']);

        $this->heartbeat();

        $runner = $this->reloaded();
        $this->assertSame('2.6.1', $runner->software_version);
        $this->assertSame(RunnerTransportClassifier::HTTP_EXTERNAL, $runner->transport);
        $this->assertSame('203.0.113.10', $runner->remote_addr);
        $this->assertNotNull($runner->plaintext_seen_at);
    }

    public function testARequestWithAnInvalidTokenRecordsNothing(): void
    {
        \Yii::$app->request->headers->set('Authorization', 'Bearer not-a-runner-token');
        $this->arriveFrom(['REMOTE_ADDR' => '203.0.113.10']);

        try {
            $this->heartbeat();
            $this->fail('an invalid token must be rejected');
        } catch (UnauthorizedHttpException) {
            // expected
        }

        $runner = $this->reloaded();
        $this->assertNull($runner->transport);
        $this->assertNull($runner->remote_addr);
        $this->assertNull($runner->plaintext_seen_at);
    }

    // -- RUNNER_TRUSTED_NETWORKS ----------------------------------------------------

    public function testNarrowedTrustedNetworksMakeA192168RunnerExternal(): void
    {
        \Yii::$app->params['runnerTrustedNetworks'] = '172.16.0.0/12';
        $this->arriveFrom(['REMOTE_ADDR' => '192.168.1.20']);

        $this->heartbeat();

        $runner = $this->reloaded();
        $this->assertSame(RunnerTransportClassifier::HTTP_EXTERNAL, $runner->transport);
        $this->assertSame('192.168.1.20', $runner->remote_addr);
        $this->assertNotNull($runner->plaintext_seen_at);
    }

    public function testATrustedNetworkOutsideTheDefaultsCountsAsInternal(): void
    {
        \Yii::$app->params['runnerTrustedNetworks'] = '203.0.113.0/24';
        $this->arriveFrom(['REMOTE_ADDR' => '203.0.113.10']);

        $this->heartbeat();

        $runner = $this->reloaded();
        $this->assertSame(RunnerTransportClassifier::HTTP_INTERNAL, $runner->transport);
        $this->assertNull($runner->plaintext_seen_at);
    }

    public function testATrustedNetworksSettingWithOnlyInvalidEntriesKeepsTheDefaults(): void
    {
        \Yii::$app->params['runnerTrustedNetworks'] = 'proxy.example.com, 10.0.0.0/abc';
        $this->arriveFrom(['REMOTE_ADDR' => '192.168.1.20']);

        $this->heartbeat();

        $this->assertSame(RunnerTransportClassifier::HTTP_INTERNAL, $this->reloaded()->transport);
    }

    // -- Self-registration ----------------------------------------------------------

    /**
     * The bootstrap secret and the new runner token travel in the
     * registration request, so it counts like any other runner request.
     */
    public function testRegistrationOverPlainHttpFromOutsideIsRecorded(): void
    {
        $this->arriveFrom(['REMOTE_ADDR' => '203.0.113.10']);
        $before = time();

        $runner = $this->registeredRunner($this->register('edge-runner'));

        $this->assertSame(RunnerTransportClassifier::HTTP_EXTERNAL, $runner->transport);
        $this->assertSame('203.0.113.10', $runner->remote_addr);
        $this->assertNotNull($runner->plaintext_seen_at);
        $this->assertGreaterThanOrEqual($before, $runner->plaintext_seen_at);
    }

    public function testRegistrationOverHttpsIsRecorded(): void
    {
        $this->arriveFrom(['REMOTE_ADDR' => '198.51.100.7', 'HTTPS' => 'on']);

        $runner = $this->registeredRunner($this->register('tls-runner'));

        $this->assertSame(RunnerTransportClassifier::HTTPS, $runner->transport);
        $this->assertSame('198.51.100.7', $runner->remote_addr);
        $this->assertNull($runner->plaintext_seen_at);
    }

    public function testReRegistrationUpdatesTheTransportAndKeepsThePlaintextHistory(): void
    {
        $this->arriveFrom(['REMOTE_ADDR' => '172.18.0.5']);
        $first = $this->registeredRunner($this->register('moving-runner'));
        $this->assertSame(RunnerTransportClassifier::HTTP_INTERNAL, $first->transport);
        $this->assertNull($first->plaintext_seen_at);

        $this->arriveFrom(['REMOTE_ADDR' => '203.0.113.10']);
        $second = $this->registeredRunner($this->register('moving-runner'));
        $this->assertSame($first->id, $second->id, 'same runner, new token');
        $this->assertSame(RunnerTransportClassifier::HTTP_EXTERNAL, $second->transport);
        $this->assertSame('203.0.113.10', $second->remote_addr);
        $this->assertNotNull($second->plaintext_seen_at);

        $this->arriveFrom(['REMOTE_ADDR' => '203.0.113.10', 'HTTPS' => 'on']);
        $third = $this->registeredRunner($this->register('moving-runner'));
        $this->assertSame(RunnerTransportClassifier::HTTPS, $third->transport);
        $this->assertSame($second->plaintext_seen_at, $third->plaintext_seen_at);
    }

    public function testRegistrationWithoutAPeerAddressLeavesTheTransportUnknown(): void
    {
        $runner = $this->registeredRunner($this->register('cli-runner'));

        $this->assertNull($runner->transport);
        $this->assertNull($runner->remote_addr);
        $this->assertNull($runner->plaintext_seen_at);
    }

    // -- Runner model -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string|null, 1: bool}>
     */
    public static function insecureTransportProvider(): array
    {
        return [
            'https' => [RunnerTransportClassifier::HTTPS, false],
            'plain HTTP from a trusted network' => [RunnerTransportClassifier::HTTP_INTERNAL, false],
            'plain HTTP from outside' => [RunnerTransportClassifier::HTTP_EXTERNAL, true],
            'not seen yet' => [null, false],
        ];
    }

    /**
     * @dataProvider insecureTransportProvider
     */
    public function testOnlyPlainHttpFromOutsideIsInsecure(?string $transport, bool $insecure): void
    {
        $runner = new Runner();
        $runner->transport = $transport;

        $this->assertSame($insecure, $runner->hasInsecureTransport());
    }

    public function testTransportChangesAppliesTheChangesToTheModelWithoutSaving(): void
    {
        $runner = $this->reloaded();

        $changes = $runner->transportChanges(['REMOTE_ADDR' => '203.0.113.10'], 1760000000);

        $this->assertSame(
            ['transport' => RunnerTransportClassifier::HTTP_EXTERNAL, 'remote_addr' => '203.0.113.10', 'plaintext_seen_at' => 1760000000],
            $changes
        );
        $this->assertSame(RunnerTransportClassifier::HTTP_EXTERNAL, $runner->transport);
        $this->assertSame('203.0.113.10', $runner->remote_addr);
        $this->assertSame(1760000000, $runner->plaintext_seen_at);
        $this->assertTrue($runner->hasInsecureTransport());
        $this->assertNull($this->reloaded()->transport, 'the caller writes the changes, not transportChanges()');
    }

    public function testTransportChangesComparesAgainstTheModelsCurrentValues(): void
    {
        $runner = $this->reloaded();
        $runner->transport = RunnerTransportClassifier::HTTP_INTERNAL;
        $runner->remote_addr = '172.18.0.5';

        $this->assertSame([], $runner->transportChanges(['REMOTE_ADDR' => '172.18.0.5'], 1760000000));
        $this->assertSame(['remote_addr' => '172.18.0.6'], $runner->transportChanges(['REMOTE_ADDR' => '172.18.0.6'], 1760000000));
        $this->assertSame('172.18.0.6', $runner->remote_addr);
    }

    public function testTransportChangesReadsTheTrustedNetworksSetting(): void
    {
        $server = ['REMOTE_ADDR' => '192.168.1.20'];

        \Yii::$app->params['runnerTrustedNetworks'] = '172.16.0.0/12';
        $this->assertSame(RunnerTransportClassifier::HTTP_EXTERNAL, (new Runner())->transportChanges($server, 1760000000)['transport']);

        \Yii::$app->params['runnerTrustedNetworks'] = '';
        $this->assertSame(RunnerTransportClassifier::HTTP_INTERNAL, (new Runner())->transportChanges($server, 1760000000)['transport']);

        unset(\Yii::$app->params['runnerTrustedNetworks']);
        $this->assertSame(
            RunnerTransportClassifier::HTTP_INTERNAL,
            (new Runner())->transportChanges($server, 1760000000)['transport'],
            'a missing setting means the defaults'
        );
    }

    // -- Helpers ------------------------------------------------------------------------

    /**
     * Simulates where the next request comes from: replaces every $_SERVER
     * key the classifier reads.
     *
     * @param array<string, string> $server
     */
    private function arriveFrom(array $server): void
    {
        foreach (self::TRANSPORT_KEYS as $key) {
            unset($_SERVER[$key]);
        }
        foreach ($server as $key => $value) {
            $_SERVER[$key] = $value;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function heartbeat(): array
    {
        $result = (new JobsController('runner-api', \Yii::$app))->runAction('heartbeat');
        $this->assertIsArray($result);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function register(string $name): array
    {
        $_ENV['RUNNER_BOOTSTRAP_SECRET'] = self::BOOTSTRAP_SECRET;
        /** @var \yii\web\Request $request */
        $request = \Yii::$app->request;
        $request->setBodyParams([
            'name' => $name,
            'group' => $this->group->name,
            'bootstrap_secret' => self::BOOTSTRAP_SECRET,
        ]);

        return (new RegisterController('runner-register', \Yii::$app))->actionRegister();
    }

    /**
     * @param array<string, mixed> $result
     */
    private function registeredRunner(array $result): Runner
    {
        $this->assertTrue($result['ok'], (string)json_encode($result));
        $this->assertIsArray($result['data']);
        $runner = Runner::findOne((int)$result['data']['runner_id']);
        $this->assertNotNull($runner);

        return $runner;
    }

    private function storeTransport(string $transport, string $remoteAddr, ?int $plaintextSeenAt): void
    {
        Runner::updateAll(
            ['transport' => $transport, 'remote_addr' => $remoteAddr, 'plaintext_seen_at' => $plaintextSeenAt],
            ['id' => $this->runner->id]
        );
    }

    private function reloaded(): Runner
    {
        $runner = Runner::findOne($this->runner->id);
        $this->assertNotNull($runner);

        return $runner;
    }
}
