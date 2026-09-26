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
}
