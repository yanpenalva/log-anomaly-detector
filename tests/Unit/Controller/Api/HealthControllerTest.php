<?php

declare(strict_types=1);

namespace Tests\Unit\Controller\Api;

use App\Controller\Api\HealthController;
use App\Utils\Config;
use flight\Engine;
use PHPUnit\Framework\TestCase;

class HealthControllerTest extends TestCase
{
    public function testHealthReturnsJsonPayload(): void
    {
        $captured = null;
        $app = $this->getMockBuilder(Engine::class)
            ->disableOriginalConstructor()
            ->addMethods(['json'])
            ->getMock();
        $app->expects($this->once())
            ->method('json')
            ->willReturnCallback(static function (array $payload) use (&$captured): void {
                $captured = $payload;
            });

        $controller = new HealthController($app, new Config(['app' => ['env' => 'testing']]));
        $controller->health();

        self::assertNotNull($captured);
        self::assertSame('ok', $captured['data']['status']);
        self::assertSame('testing', $captured['data']['env']);
        self::assertArrayHasKey('time', $captured['data']);
    }
}
