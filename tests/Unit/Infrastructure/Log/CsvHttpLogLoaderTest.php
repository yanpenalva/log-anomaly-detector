<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Log;

use App\Domain\Anomaly\HttpMethod;
use App\Infrastructure\Log\CsvDatasetException;
use App\Infrastructure\Log\CsvHttpLogLoader;
use PHPUnit\Framework\TestCase;

class CsvHttpLogLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/csv_loader_' . uniqid('', true);
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function writeFile(string $name, string $content): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    public function testLoadsValidDataset(): void
    {
        $path = $this->writeFile('ok.csv', <<<'CSV'
            method,endpoint,status_code,response_time,request_size,hour
            GET,/users,200,118,1024,10
            POST,/payments,201,340,1540,11

            GET,/health,200,12,150,23
            CSV);

        $entries = (new CsvHttpLogLoader())->load($path);

        self::assertCount(3, $entries);
        self::assertSame(HttpMethod::Post, $entries[1]->method);
        self::assertSame('/payments', $entries[1]->endpoint);
        self::assertSame(340.0, $entries[1]->responseTime);
        self::assertSame(23, $entries[2]->hour);
    }

    public function testColumnOrderIsIrrelevant(): void
    {
        $path = $this->writeFile('reordered.csv', <<<'CSV'
            hour,endpoint,request_size,method,status_code,response_time
            9,/orders,512,GET,200,77
            CSV);

        $entries = (new CsvHttpLogLoader())->load($path);

        self::assertSame('/orders', $entries[0]->endpoint);
        self::assertSame(512, $entries[0]->requestSize);
        self::assertSame(77.0, $entries[0]->responseTime);
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(CsvDatasetException::class);
        $this->expectExceptionMessage('not found');
        (new CsvHttpLogLoader())->load($this->dir . '/nope.csv');
    }

    public function testEmptyFileThrows(): void
    {
        $path = $this->writeFile('empty.csv', '');

        $this->expectException(CsvDatasetException::class);
        $this->expectExceptionMessage('header');
        (new CsvHttpLogLoader())->load($path);
    }

    public function testMissingHeaderColumnThrows(): void
    {
        $path = $this->writeFile('bad_header.csv', <<<'CSV'
            method,endpoint,status_code,response_time,request_size
            GET,/users,200,118,1024
            CSV);

        $this->expectException(CsvDatasetException::class);
        $this->expectExceptionMessage('hour');
        (new CsvHttpLogLoader())->load($path);
    }

    public function testRowMissingColumnThrowsWithLineNumber(): void
    {
        $path = $this->writeFile('short_row.csv', <<<'CSV'
            method,endpoint,status_code,response_time,request_size,hour
            GET,/users,200,118,1024,10
            GET,/users,200,118
            CSV);

        try {
            (new CsvHttpLogLoader())->load($path);
            self::fail('Expected CsvDatasetException');
        } catch (CsvDatasetException $e) {
            self::assertStringContainsString('line 3', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidRowProvider(): array
    {
        $header = 'method,endpoint,status_code,response_time,request_size,hour';

        return [
            'unknown method' => [$header . "\nBREW,/x,200,10,10,10", 'method'],
            'invalid status' => [$header . "\nGET,/x,99,10,10,10", 'status_code'],
            'negative response time' => [$header . "\nGET,/x,200,-5,10,10", 'response_time'],
            'non numeric response time' => [$header . "\nGET,/x,200,fast,10,10", 'response_time'],
            'negative request size' => [$header . "\nGET,/x,200,10,-3,10", 'request_size'],
            'invalid hour' => [$header . "\nGET,/x,200,10,10,24", 'hour'],
            'empty endpoint' => [$header . "\nGET,,200,10,10,10", 'endpoint'],
        ];
    }

    /**
     * @dataProvider invalidRowProvider
     */
    public function testInvalidRowThrowsWithLineNumber(string $content, string $field): void
    {
        $path = $this->writeFile('invalid.csv', $content);

        try {
            (new CsvHttpLogLoader())->load($path);
            self::fail(sprintf('Expected CsvDatasetException mentioning "%s"', $field));
        } catch (CsvDatasetException $e) {
            self::assertStringContainsString($field, $e->getMessage());
            self::assertStringContainsString('line 2', $e->getMessage());
        }
    }

    public function testHeaderOnlyFileThrowsEmptyDataset(): void
    {
        $path = $this->writeFile('header_only.csv', "method,endpoint,status_code,response_time,request_size,hour\n");

        $this->expectException(CsvDatasetException::class);
        $this->expectExceptionMessage('no data rows');
        (new CsvHttpLogLoader())->load($path);
    }
}
