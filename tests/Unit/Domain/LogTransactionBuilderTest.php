<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Anomaly\HttpLogEntry;
use App\Domain\Anomaly\HttpMethod;
use App\Domain\Anomaly\LogTransactionBuilder;
use PHPUnit\Framework\TestCase;

class LogTransactionBuilderTest extends TestCase
{
    private LogTransactionBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new LogTransactionBuilder();
    }

    public function testDiscretizesEveryAttribute(): void
    {
        $transaction = $this->builder->toTransaction(
            new HttpLogEntry(HttpMethod::Post, '/payments', 201, 350.0, 1500, 12)
        );

        self::assertSame([
            'method=POST',
            'endpoint=/payments',
            'status=201',
            'hour=12',
            'time=medium',
            'size=medium',
        ], $transaction);
    }

    public function testBucketBoundaries(): void
    {
        $fastSmall = $this->builder->toTransaction(
            new HttpLogEntry(HttpMethod::Get, '/x', 200, 99.9, 499, 3)
        );
        $slowLarge = $this->builder->toTransaction(
            new HttpLogEntry(HttpMethod::Get, '/x', 500, 1000.0, 5000, 23)
        );

        self::assertSame('time=fast', $fastSmall[4]);
        self::assertSame('size=small', $fastSmall[5]);
        self::assertSame('hour=03', $fastSmall[3]);
        self::assertSame('time=slow', $slowLarge[4]);
        self::assertSame('size=large', $slowLarge[5]);
    }
}
