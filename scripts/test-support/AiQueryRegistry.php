<?php

declare(strict_types=1);

namespace Fleetbase\Ai\Support;

class AiQueryRegistry
{
    private array $resources = [];

    public function register(AiQueryableResource $resource): void
    {
        $this->resources[$resource->key] = $resource;

        foreach ($resource->aliases as $alias) {
            $this->resources[$alias] = $resource;
        }
    }

    public function find(string $key): ?AiQueryableResource
    {
        return $this->resources[$key] ?? null;
    }
}
