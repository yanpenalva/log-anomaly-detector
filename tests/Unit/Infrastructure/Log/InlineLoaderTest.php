<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Log;

use App\Infrastructure\Log\CsvHttpLogLoader;
use App\Infrastructure\Log\NginxAccessLogLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class InlineLoaderTest extends TestCase
{
    public function testCsvStringParsesLikeFile(): void
    {
        $csv = "method,endpoint,status_code,response_time,request_size,hour\n"
            . "GET,/users,200,120.5,1000,10\n"
            . "POST,/payments,201,340,1500,12\n";

        $entries = (new CsvHttpLogLoader())->loadString($csv);

        self::assertCount(2, $entries);
        self::assertSame('/users', $entries[0]->endpoint);
        self::assertSame(12, $entries[1]->hour);
    }

    public function testCsvStringWithInvalidRowThrowsWithLineNumber(): void
    {
        $csv = "method,endpoint,status_code,response_time,request_size,hour\n"
            . "GET,/users,9999,120,1000,10\n";

        $this->expectException(RuntimeException::class);
        (new CsvHttpLogLoader())->loadString($csv);
    }

    public function testNginxStringParsesCombinedLines(): void
    {
        $log = "127.0.0.1 - - [10/Oct/2026:13:55:36 -0300] \"GET /users HTTP/1.1\" 200 1000\n"
            . "127.0.0.1 - - [10/Oct/2026:13:56:01 -0300] \"GET /.env HTTP/1.1\" 404 60 0.009\n";

        $entries = (new NginxAccessLogLoader())->loadString($log);

        self::assertCount(2, $entries);
        self::assertSame('/.env', $entries[1]->endpoint);
        self::assertSame(9.0, $entries[1]->responseTime);
    }

    public function testEmptyStringThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new NginxAccessLogLoader())->loadString("   \n  \n");
    }
}
