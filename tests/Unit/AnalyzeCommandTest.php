<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Command\AnalyzeCommand;
use App\Infrastructure\Log\CsvHttpLogLoader;
use App\Infrastructure\Log\NginxAccessLogLoader;
use App\Utils\Config;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AnalyzeCommandTest extends TestCase
{
    public function testLoaderForResolvesByExtension(): void
    {
        self::assertInstanceOf(CsvHttpLogLoader::class, AnalyzeCommand::loaderFor('datasets/development.csv'));
        self::assertInstanceOf(CsvHttpLogLoader::class, AnalyzeCommand::loaderFor('DATA.LOG.CSV'));
        self::assertInstanceOf(NginxAccessLogLoader::class, AnalyzeCommand::loaderFor('/var/log/nginx/access.log'));
    }

    public function testLoaderForRejectsUnknownExtension(): void
    {
        $this->expectException(RuntimeException::class);
        AnalyzeCommand::loaderFor('/var/log/access.txt');
    }

    public function testResolveParametersUsesConfigDefaults(): void
    {
        $config = new Config(['anomaly' => ['epsilon' => 0.4, 'minimum_samples' => 7]]);
        $parameters = AnalyzeCommand::resolveParameters($config, null, null);

        self::assertSame(0.4, $parameters->epsilon);
        self::assertSame(7, $parameters->minimumSamples);
    }

    public function testResolveParametersCliOptionsWin(): void
    {
        $config = new Config(['anomaly' => ['epsilon' => 0.4, 'minimum_samples' => 7]]);
        $parameters = AnalyzeCommand::resolveParameters($config, '0.5', '9');

        self::assertSame(0.5, $parameters->epsilon);
        self::assertSame(9, $parameters->minimumSamples);
    }

    public function testResolveParametersRejectsInvalidEpsilon(): void
    {
        $config = new Config(['anomaly' => ['epsilon' => 0.4, 'minimum_samples' => 7]]);

        $this->expectException(InvalidArgumentException::class);
        AnalyzeCommand::resolveParameters($config, '-1', null);
    }
}
