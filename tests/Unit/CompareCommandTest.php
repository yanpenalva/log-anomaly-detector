<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Command\CompareCommand;
use App\Utils\Config;
use PHPUnit\Framework\TestCase;

class CompareCommandTest extends TestCase
{
    public function testResolveKMeansParametersUsesConfigDefaults(): void
    {
        $config = new Config(['anomaly' => ['kmeans_clusters' => 6, 'minimum_samples' => 7]]);
        $parameters = CompareCommand::resolveKMeansParameters($config, null, 7);

        self::assertSame(6, $parameters->clusters);
        self::assertSame(7, $parameters->minimumSamples);
    }

    public function testResolveKMeansParametersCliOptionWins(): void
    {
        $config = new Config(['anomaly' => ['kmeans_clusters' => 6]]);
        $parameters = CompareCommand::resolveKMeansParameters($config, '3', 5);

        self::assertSame(3, $parameters->clusters);
        self::assertSame(5, $parameters->minimumSamples);
    }

    public function testResolveKMeansParametersFallsBackToDefaultClusters(): void
    {
        $parameters = CompareCommand::resolveKMeansParameters(new Config([]), null, 5);

        self::assertSame(4, $parameters->clusters);
    }

    public function testResolveLofParametersSharesMinimumSamples(): void
    {
        $parameters = CompareCommand::resolveLofParameters(null, 7);

        self::assertSame(7, $parameters->minPts);
        self::assertSame(1.5, $parameters->threshold);
    }

    public function testResolveLofParametersCliThresholdWins(): void
    {
        $parameters = CompareCommand::resolveLofParameters('2.5', 7);

        self::assertSame(2.5, $parameters->threshold);
    }

    public function testResolveIsolationForestParametersDefaults(): void
    {
        $parameters = CompareCommand::resolveIsolationForestParameters(null, null, null);

        self::assertSame(100, $parameters->trees);
        self::assertSame(256, $parameters->subsampleSize);
        self::assertSame(0.6, $parameters->threshold);
        self::assertNull($parameters->seed);
    }
}
