<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads .github/workflows/fleetbase-release.yml for FleetbaseReleaseCheckTest.
 */
class ReleaseWorkflow
{
    public const FILE = '.github/workflows/fleetbase-release.yml';

    public static function text(): string
    {
        return DocsSource::text(self::FILE);
    }

    /**
     * @return array<string, mixed>
     */
    public static function parsed(): array
    {
        $parsed = Yaml::parse(self::text());

        return is_array($parsed) === true ? $parsed : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function job(string $name): array
    {
        return self::parsed()['jobs'][$name] ?? [];
    }

    /**
     * Every step of every job, in file order.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function steps(): array
    {
        $steps = [];
        foreach (self::parsed()['jobs'] as $job) {
            foreach ($job['steps'] ?? [] as $step) {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    /**
     * The run scripts of one job, joined in order.
     */
    public static function runs(string $job): string
    {
        return implode("\n", array_column(self::job($job)['steps'] ?? [], 'run'));
    }
}
