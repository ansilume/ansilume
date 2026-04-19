<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

use app\models\Job;
use app\services\notification\JobPayloadBuilder;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;

/**
 * Regression coverage for JobPayloadBuilder::jobUrl().
 *
 * Original bug: notifications surfaced the internal docker hostname
 * (`http://nginx/job/view?id=…`) instead of the operator-configured
 * external URL. The runner container finalises jobs via an internal
 * POST to nginx, and the HTTP request's Host header during that call
 * is "nginx". The old code picked request->hostInfo over appBaseUrl
 * whenever a request was bound, so the internal hostname leaked into
 * every notification the runner caused to fire. These tests pin the
 * inverted priority: appBaseUrl (APP_URL) always wins when set.
 */
class JobPayloadBuilderTest extends TestCase
{
    private function makeJob(int $id = 42): Job
    {
        $job = $this->getMockBuilder(Job::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();

        $attr = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $attr->setAccessible(true);
        $attr->setValue($job, [
            'id' => $id,
            'status' => 'successful',
            'exit_code' => 0,
            'started_at' => 1000,
            'finished_at' => 1005,
        ]);

        // Populate the _related cache so __get('jobTemplate') / __get('launcher')
        // short-circuit to our null values without invoking the typed
        // getJobTemplate()/getLauncher() relations (which can't return null).
        $rel = new \ReflectionProperty(BaseActiveRecord::class, '_related');
        $rel->setAccessible(true);
        $rel->setValue($job, ['jobTemplate' => null, 'launcher' => null]);

        return $job;
    }

    public function testJobUrlPrefersAppBaseUrlOverRequestHostInfo(): void
    {
        $origParams = \Yii::$app->params;
        \Yii::$app->params['appBaseUrl'] = 'https://ansilume.example.com';

        $origRequest = \Yii::$app->has('request') ? \Yii::$app->request : null;
        \Yii::$app->set('request', new class extends \yii\web\Request {
            public function getHostInfo(): string
            {
                // Simulates the runner→nginx finalise call: the request
                // came from inside docker so Host is the service name.
                return 'http://nginx';
            }
        });

        try {
            $payload = JobPayloadBuilder::build($this->makeJob(123));

            $this->assertStringStartsWith(
                'https://ansilume.example.com',
                $payload['job']['url'],
                'job.url must be built from appBaseUrl, not the request Host header (regression: http://nginx leaked into notifications).'
            );
            $this->assertStringNotContainsString(
                '://nginx',
                $payload['job']['url'],
                'The internal docker hostname must never appear in a payload destined for end users.'
            );
            $this->assertStringContainsString('id=123', $payload['job']['url']);
        } finally {
            \Yii::$app->params = $origParams;
            if ($origRequest !== null) {
                \Yii::$app->set('request', $origRequest);
            }
        }
    }

    public function testJobUrlFallsBackToHostInfoWhenAppBaseUrlIsEmpty(): void
    {
        $origParams = \Yii::$app->params;
        unset(\Yii::$app->params['appBaseUrl']);

        $origRequest = \Yii::$app->has('request') ? \Yii::$app->request : null;
        \Yii::$app->set('request', new class extends \yii\web\Request {
            public function getHostInfo(): string
            {
                return 'https://from-request.example';
            }
        });

        try {
            $payload = JobPayloadBuilder::build($this->makeJob(7));
            $this->assertStringStartsWith('https://from-request.example', $payload['job']['url']);
            $this->assertStringContainsString('id=7', $payload['job']['url']);
        } finally {
            \Yii::$app->params = $origParams;
            if ($origRequest !== null) {
                \Yii::$app->set('request', $origRequest);
            }
        }
    }

}
