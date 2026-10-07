<?php

declare(strict_types=1);

namespace Fleetbase\TestSupport;

use Illuminate\Container\Container;

class TestContainer extends Container
{
    public array $registeredProviders = [];

    public function environment(array|string ...$environments): bool|string
    {
        return $environments === []
            ? 'testing'
            : in_array('testing', is_array($environments[0] ?? null) === true ? $environments[0] : $environments, true);
    }

    public function runningUnitTests(): bool
    {
        return true;
    }

    public function runningInConsole(): bool
    {
        return true;
    }

    public function register($provider, $force = false)
    {
        $this->registeredProviders[] = $provider;

        return $provider;
    }
}
