<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use flight\Engine;
use flight\net\Request;
use flight\net\Response;
use flight\util\Collection;
use PHPUnit\Framework\TestCase;
use App\Middleware\CsrfMiddleware;

/**
 * @property Engine&\PHPUnit\Framework\MockObject\MockObject $app
 */
class CsrfMiddlewareTest extends TestCase
{
    /** @var Engine&\PHPUnit\Framework\MockObject\MockObject */
    private Engine $app;

    /** @var list<string> */
    private array $setCookies = [];

    private int $halts = 0;

    protected function setUp(): void
    {
        $this->app = $this->getMockBuilder(Engine::class)
            ->disableOriginalConstructor()
            ->addMethods(['request', 'response', 'halt'])
            ->getMock();
        $this->setCookies = [];
        $this->halts = 0;

        $response = $this->getMockBuilder(Response::class)
            ->onlyMethods(['header'])
            ->getMock();
        $response->method('header')->willReturnCallback(function (string $name, ?string $value) use ($response): Response {
            if ($name === 'Set-Cookie') {
                $this->setCookies[] = (string) $value;
            }

            return $response;
        });

        $this->app->method('response')->willReturn($response);
        $this->app->method('halt')->willReturnCallback(function (int $code, string $message = ''): void {
            $this->halts++;
        });
    }

    public function testGetWithoutCookieIssuesToken(): void
    {
        $middleware = new CsrfMiddleware($this->app);
        $middleware->before($this->requestParams('GET', []));

        self::assertCount(1, $this->setCookies);
        self::assertStringContainsString('csrf_token=', $this->setCookies[0]);
        self::assertStringContainsString('SameSite=Lax', $this->setCookies[0]);
    }

    public function testPostWithoutCookiesIsUntouched(): void
    {
        $middleware = new CsrfMiddleware($this->app);
        $middleware->before($this->requestParams('POST', []));

        self::assertSame(0, $this->halts);
    }

    public function testPostWithMatchingTokenPasses(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'tok';
        $middleware = new CsrfMiddleware($this->app);
        $middleware->before($this->requestParams('POST', ['csrf_token' => 'tok']));

        self::assertSame(0, $this->halts);
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    public function testPostWithWrongTokenHalts(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong';
        $middleware = new CsrfMiddleware($this->app);
        $middleware->before($this->requestParams('POST', ['csrf_token' => 'right']));

        self::assertSame(1, $this->halts);
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    public function testPostWithMissingHeaderHalts(): void
    {
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $middleware = new CsrfMiddleware($this->app);
        $middleware->before($this->requestParams('POST', ['csrf_token' => 'right']));

        self::assertSame(1, $this->halts);
    }

    /**
     * @param array<string, string> $cookies
     *
     * @return array<string, mixed>
     */
    private function requestParams(string $method, array $cookies = ['csrf_token' => 'x']): array
    {
        $request = new Request([
            'url' => '/',
            'base' => '',
            'method' => $method,
            'type' => 'application/json',
            'query' => new Collection(),
            'data' => new Collection(),
            'cookies' => new Collection($cookies),
            'files' => new Collection(),
        ]);
        $this->app->method('request')->willReturn($request);

        return [];
    }
}
