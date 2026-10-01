<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middleware\RateLimitMiddleware;
use App\Utils\Config;
use flight\Engine;
use flight\net\Request;
use flight\util\Collection;
use PHPUnit\Framework\TestCase;

/**
 * @property Engine&\PHPUnit\Framework\MockObject\MockObject $app
 */
class RateLimitMiddlewareTest extends TestCase
{
    /** @var Engine&\PHPUnit\Framework\MockObject\MockObject */
    private Engine $app;

    private int $halts = 0;

    protected function setUp(): void
    {
        RateLimitMiddleware::resetState();
        $this->app = $this->getMockBuilder(Engine::class)
            ->disableOriginalConstructor()
            ->addMethods(['request', 'halt'])
            ->getMock();
        $this->halts = 0;
        $this->app->method('halt')->willReturnCallback(function (): void {
            $this->halts++;
        });
    }

    public function testBlocksWhenBucketEmpty(): void
    {
        $middleware = new RateLimitMiddleware($this->app, new Config(['anomaly' => [
            'rate_limit_capacity' => 2,
            'rate_limit_refill_per_second' => 0,
        ]]));
        $this->givenRequest('POST', '10.1.0.1');

        $middleware->before([]);
        $middleware->before([]);
        $middleware->before([]);

        self::assertSame(1, $this->halts, 'third POST with capacity 2 and no refill must halt');
    }

    public function testRefillRestoresTokens(): void
    {
        $middleware = new RateLimitMiddleware($this->app, new Config(['anomaly' => [
            'rate_limit_capacity' => 1,
            'rate_limit_refill_per_second' => 1000,
        ]]));
        $this->givenRequest('POST', '10.1.0.2');

        $middleware->before([]);
        usleep(2000);
        $middleware->before([]);

        self::assertSame(0, $this->halts, 'high refill rate must restore the token between calls');
    }

    public function testGetIsNeverLimited(): void
    {
        $middleware = new RateLimitMiddleware($this->app, new Config(['anomaly' => [
            'rate_limit_capacity' => 0,
            'rate_limit_refill_per_second' => 0,
        ]]));
        $this->givenRequest('GET', '10.1.0.3');

        $middleware->before([]);
        $middleware->before([]);

        self::assertSame(0, $this->halts);
    }

    private function givenRequest(string $method, string $ip): void
    {
        $this->app->method('request')->willReturn(new Request([
            'url' => '/api/v1/analyze',
            'base' => '',
            'method' => $method,
            'type' => 'application/json',
            'ip' => $ip,
            'query' => new Collection(),
            'data' => new Collection(),
            'cookies' => new Collection(),
            'files' => new Collection(),
        ]));
    }
}
