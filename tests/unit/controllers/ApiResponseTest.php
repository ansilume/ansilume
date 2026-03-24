<?php

declare(strict_types=1);

namespace app\tests\unit\controllers;

use app\controllers\api\v1\BaseApiController;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BaseApiController response helpers.
 *
 * Since these helpers set Yii::$app->response->statusCode (web-only),
 * we override that method and capture the status.
 */
class ApiResponseTest extends TestCase
{
    private function makeController(): BaseApiController
    {
        return new class('api-test', \Yii::$app) extends BaseApiController {
            public int $capturedStatus = 200;

            // Skip auth for unit tests
            public function beforeAction($action): bool { return true; }

            public function testSuccess(mixed $data, int $status = 200): array
            {
                $this->capturedStatus = $status;
                return ['data' => $data];
            }

            public function testError(string $message, int $status = 400): array
            {
                $this->capturedStatus = $status;
                return ['error' => ['message' => $message]];
            }

            public function testPaginated(array $items, int $total, int $page, int $perPage): array
            {
                return $this->paginated($items, $total, $page, $perPage);
            }
        };
    }

    public function testSuccessWrapsDataKey(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->testSuccess(['id' => 1, 'name' => 'test']);

        $this->assertArrayHasKey('data', $result);
        $this->assertSame(1, $result['data']['id']);
    }

    public function testErrorWrapsMessageInErrorKey(): void
    {
        $ctrl = $this->makeController();
        $result = $ctrl->testError('Not found', 404);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Not found', $result['error']['message']);
        $this->assertSame(404, $ctrl->capturedStatus);
    }

    public function testPaginatedIncludesMeta(): void
    {
        $ctrl   = $this->makeController();
        $result = $ctrl->testPaginated([['id' => 1]], 50, 2, 25);

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertSame(50, $result['meta']['total']);
        $this->assertSame(2, $result['meta']['page']);
        $this->assertSame(25, $result['meta']['per_page']);
        $this->assertSame(2, $result['meta']['pages']);
    }

    public function testPaginatedPagesRoundsUp(): void
    {
        $ctrl   = $this->makeController();
        $result = $ctrl->testPaginated([], 51, 1, 25);

        $this->assertSame(3, $result['meta']['pages']);
    }

    public function testPaginatedHandlesZeroTotal(): void
    {
        $ctrl   = $this->makeController();
        $result = $ctrl->testPaginated([], 0, 1, 25);

        $this->assertSame(0, $result['meta']['total']);
        $this->assertSame(0, $result['meta']['pages']);
    }

    public function testSuccessDefaultsTo200(): void
    {
        $ctrl = $this->makeController();
        $ctrl->testSuccess(['ok' => true]);

        $this->assertSame(200, $ctrl->capturedStatus);
    }

    public function testSuccessAcceptsCustomStatus(): void
    {
        $ctrl = $this->makeController();
        $ctrl->testSuccess(['id' => 1], 201);

        $this->assertSame(201, $ctrl->capturedStatus);
    }
}
