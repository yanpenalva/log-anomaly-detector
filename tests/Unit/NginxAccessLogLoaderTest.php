<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Log\AccessLogException;
use App\Infrastructure\Log\NginxAccessLogLoader;
use PHPUnit\Framework\TestCase;

class NginxAccessLogLoaderTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = sys_get_temp_dir() . '/log_anomaly_access_' . uniqid('', true) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }
    }

    public function testParsesCombinedFormatWithOptionalRequestTime(): void
    {
        file_put_contents($this->logPath, implode("\n", [
            '10.0.0.1 - frank [15/Mar/2026:10:00:01 +0000] "GET /users?page=2 HTTP/1.1" 200 118 "-" "curl/8.0" 0.118',
            '10.0.0.2 - - [15/Mar/2026:03:00:02 +0000] "POST /payments HTTP/1.1" 201 - "-" "api/1.0"',
        ]));

        $entries = (new NginxAccessLogLoader())->load($this->logPath);

        self::assertCount(2, $entries);

        self::assertSame('GET', $entries[0]->method->value);
        self::assertSame('/users?page=2', $entries[0]->endpoint);
        self::assertSame(200, $entries[0]->statusCode);
        self::assertSame(118.0, $entries[0]->responseTime);
        self::assertSame(118, $entries[0]->requestSize);
        self::assertSame(10, $entries[0]->hour);

        self::assertSame('POST', $entries[1]->method->value);
        self::assertSame(201, $entries[1]->statusCode);
        self::assertSame(0.0, $entries[1]->responseTime);
        self::assertSame(0, $entries[1]->requestSize);
        self::assertSame(3, $entries[1]->hour);
    }

    public function testSkipsMalformedAndUnknownVerbLines(): void
    {
        file_put_contents($this->logPath, implode("\n", [
            '10.0.0.1 - - [15/Mar/2026:10:00:01 +0000] "GET /users HTTP/1.1" 200 118 "-" "curl/8.0" 0.118',
            'not a log line at all',
            '10.0.0.2 - - [15/Mar/2026:10:00:02 +0000] "FOO /users HTTP/1.1" 200 10 "-" "x" 0.010',
            '10.0.0.3 - - [not a date] "GET /users HTTP/1.1" 200 10 "-" "x" 0.010',
        ]));

        $entries = (new NginxAccessLogLoader())->load($this->logPath);

        self::assertCount(1, $entries);
        self::assertSame('/users', $entries[0]->endpoint);
    }

    public function testThrowsOnMissingFile(): void
    {
        $this->expectException(AccessLogException::class);
        (new NginxAccessLogLoader())->load($this->logPath);
    }

    public function testThrowsWhenNoLineIsParseable(): void
    {
        file_put_contents($this->logPath, "garbage\nmore garbage\n");

        $this->expectException(AccessLogException::class);
        (new NginxAccessLogLoader())->load($this->logPath);
    }
}
