<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpMethod;
use App\Domain\Anomaly\InvalidHttpLogEntry;
use PHPUnit\Framework\TestCase;

class HttpLogEntryTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'method' => 'GET',
            'endpoint' => '/users',
            'status_code' => 200,
            'response_time' => 118.0,
            'request_size' => 1024,
            'hour' => 10,
        ];
    }

    public function testFromArrayBuildsEntry(): void
    {
        $entry = HttpLogEntry::fromArray($this->validPayload());

        self::assertSame(HttpMethod::Get, $entry->method);
        self::assertSame('/users', $entry->endpoint);
        self::assertSame(200, $entry->statusCode);
        self::assertSame(118.0, $entry->responseTime);
        self::assertSame(1024, $entry->requestSize);
        self::assertSame(10, $entry->hour);
    }

    public function testFromArrayAcceptsNumericStringsAndMixedCaseVerb(): void
    {
        $entry = HttpLogEntry::fromArray([
            'method' => 'post',
            'endpoint' => '/payments',
            'status_code' => '201',
            'response_time' => '340.5',
            'request_size' => '1540',
            'hour' => '11',
        ]);

        self::assertSame(HttpMethod::Post, $entry->method);
        self::assertSame(201, $entry->statusCode);
        self::assertSame(340.5, $entry->responseTime);
        self::assertSame(1540, $entry->requestSize);
        self::assertSame(11, $entry->hour);
    }

    public function testToArrayRoundTrip(): void
    {
        $entry = HttpLogEntry::fromArray($this->validPayload());

        self::assertSame($this->validPayload(), $entry->toArray());
    }

    public function testMissingFieldThrows(): void
    {
        $payload = $this->validPayload();
        unset($payload['hour']);

        $this->expectException(InvalidHttpLogEntry::class);
        $this->expectExceptionMessage('hour');
        HttpLogEntry::fromArray($payload);
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function invalidProvider(): array
    {
        return [
            'unknown verb' => ['method', 'BREW'],
            'empty endpoint' => ['endpoint', ''],
            'control chars in endpoint' => ["endpoint", "/us\0ers"],
            'status below range' => ['status_code', 99],
            'status above range' => ['status_code', 600],
            'negative response time' => ['response_time', -1],
            'response time not numeric' => ['response_time', 'fast'],
            'negative request size' => ['request_size', -5],
            'hour too high' => ['hour', 24],
            'hour negative' => ['hour', -1],
        ];
    }

    /**
     * @dataProvider invalidProvider
     */
    public function testInvalidFieldsThrow(string $field, mixed $value): void
    {
        $payload = $this->validPayload();
        $payload[$field] = $value;

        try {
            HttpLogEntry::fromArray($payload);
            self::fail(sprintf('Expected InvalidHttpLogEntry for field "%s"', $field));
        } catch (InvalidHttpLogEntry $e) {
            self::assertStringContainsString($field, $e->getMessage());
        }
    }

    public function testNonStringMethodThrows(): void
    {
        $payload = $this->validPayload();
        $payload['method'] = 42;

        $this->expectException(InvalidHttpLogEntry::class);
        HttpLogEntry::fromArray($payload);
    }

    public function testInvalidUtf8EndpointThrows(): void
    {
        $payload = $this->validPayload();
        $payload['endpoint'] = "/users/\xB1\xFE";

        $this->expectException(InvalidHttpLogEntry::class);
        $this->expectExceptionMessage('UTF-8');
        HttpLogEntry::fromArray($payload);
    }
}
