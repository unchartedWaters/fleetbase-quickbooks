<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Stands in for the cache facade root and records the lifetime of every add and put,
 * passing each call on to the real store.
 */
class CacheTtlRecorder
{
    /** @var array<int, array{0: string, 1: mixed}> */
    public array $ttls = [];

    public function __construct(private object $inner)
    {
    }

    /**
     * Runs the callback with this recorder as the cache and returns the lifetimes it saw.
     *
     * @return array<int, array{0: string, 1: mixed}>
     */
    public static function during(callable $callback): array
    {
        $real     = Cache::getFacadeRoot();
        $recorder = new self($real);
        Cache::swap($recorder);
        try {
            $callback();
        } finally {
            Cache::swap($real);
        }

        return $recorder->ttls;
    }

    public function add(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->ttls[] = ['add', $ttl];

        return $this->inner->add($key, $value, $ttl);
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->ttls[] = ['put', $ttl];

        return $this->inner->put($key, $value, $ttl);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->inner->get($key, $default);
    }

    public function forget(string $key): bool
    {
        return $this->inner->forget($key);
    }

    public function flush(): bool
    {
        return $this->inner->flush();
    }
}
