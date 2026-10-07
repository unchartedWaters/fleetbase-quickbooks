<?php

declare(strict_types=1);

namespace Fleetbase\TestSupport;

use Closure;

class RouteRegistrar
{
    public static array $routes = [];

    public static function reset(): void
    {
        self::$routes = [];
    }

    public function prefix(string $prefix): self
    {
        return $this;
    }

    public function namespace(string $namespace): self
    {
        return $this;
    }

    public function group(array|Closure $attributes, ?Closure $callback = null): self
    {
        ($callback ?? $attributes)($this);

        return $this;
    }

    public function get(string $uri, mixed $action): self
    {
        self::$routes[] = ['GET', $uri, $action];

        return $this;
    }

    public function post(string $uri, mixed $action): self
    {
        self::$routes[] = ['POST', $uri, $action];

        return $this;
    }

    public function fleetbaseRoutes(string $resource, ?Closure $callback = null): self
    {
        self::$routes[] = ['RESOURCE', $resource, null];

        if ($callback !== null) {
            $callback($this, fn (string $method): string => $resource . 'Controller@' . $method);
        }

        return $this;
    }
}
