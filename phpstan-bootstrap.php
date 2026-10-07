<?php

declare(strict_types=1);

if (function_exists('config') === FALSE) {
    function config(mixed $key = null, mixed $default = null): mixed
    {
        return $default;
    }
}

if (function_exists('session') === FALSE) {
    function session(array|string|null $key = null, mixed $default = null): mixed
    {
        return $default;
    }
}

if (function_exists('abort') === FALSE) {
    function abort(int $code, string $message = '', array $headers = []): never
    {
        throw new RuntimeException($message, $code);
    }
}

if (function_exists('response') === FALSE) {
    function response(): Illuminate\Routing\ResponseFactory
    {
        throw new RuntimeException('Stub for static analysis only.');
    }
}

if (function_exists('app') === FALSE) {
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        return Illuminate\Container\Container::getInstance()->make($abstract, $parameters);
    }
}

if (function_exists('event') === FALSE) {
    function event(object|string|null $event = null, mixed $payload = []): mixed
    {
        return null;
    }
}

if (function_exists('now') === FALSE) {
    function now(\DateTimeZone|string|null $tz = null): Illuminate\Support\Carbon
    {
        return Illuminate\Support\Carbon::now($tz);
    }
}
