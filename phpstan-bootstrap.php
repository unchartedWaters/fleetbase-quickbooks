<?php

declare(strict_types=1);

if (function_exists('config') === false) {
    function config(mixed $key = null, mixed $default = null): mixed
    {
        return $default;
    }
}

if (function_exists('session') === false) {
    function session(array|string|null $key = null, mixed $default = null): mixed
    {
        return $default;
    }
}

if (function_exists('abort') === false) {
    function abort(int $code, string $message = '', array $headers = []): never
    {
        throw new RuntimeException($message, $code);
    }
}

if (function_exists('response') === false) {
    function response(): Illuminate\Routing\ResponseFactory
    {
        throw new RuntimeException('Stub for static analysis only.');
    }
}

if (function_exists('app') === false) {
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        return Illuminate\Container\Container::getInstance()->make($abstract, $parameters);
    }
}

if (function_exists('event') === false) {
    function event(object|string|null $event = null, mixed $payload = []): mixed
    {
        return null;
    }
}

if (function_exists('now') === false) {
    function now(DateTimeZone|string|null $tz = null): Illuminate\Support\Carbon
    {
        return Illuminate\Support\Carbon::now($tz);
    }
}
