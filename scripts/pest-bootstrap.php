<?php

declare(strict_types=1);

$autoloadCandidates = [
    getcwd() . '/server_vendor/autoload.php',
    getcwd() . '/vendor/autoload.php',
];

foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate) === true) {
        require_once $candidate;
        break;
    }
}

if (class_exists('Illuminate\Support\Str') === true && Illuminate\Support\Str::hasMacro('humanize') === false) {
    Illuminate\Support\Str::macro('humanize', function (string $value, bool $title = true): string {
        $humanized = str_replace(['-', '_'], ' ', Illuminate\Support\Str::snake($value));

        return $title === true ? Illuminate\Support\Str::title($humanized) : $humanized;
    });
}

if (function_exists('config') === false) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        if (class_exists('Illuminate\Container\Container') === true) {
            $container = Illuminate\Container\Container::getInstance();

            if ($container->bound('config') === true) {
                $repository = $container->make('config');

                return $key === null ? $repository : $repository->get($key, $default);
            }
        }

        return $default;
    }
}

if (class_exists('Illuminate\Container\Container') === true && class_exists('Illuminate\Support\Facades\Facade') === true) {
    $app = Illuminate\Container\Container::getInstance();

    if (method_exists($app, 'environment') === false) {
        if (class_exists('Fleetbase\TestSupport\TestContainer') === false) {
            require_once __DIR__ . '/test-support/TestContainer.php';
        }

        $app = new Fleetbase\TestSupport\TestContainer();
        Illuminate\Container\Container::setInstance($app);
    }

    Illuminate\Support\Facades\Facade::setFacadeApplication($app);

    if ($app->bound('http') === false && class_exists('Illuminate\Http\Client\Factory') === true) {
        $app->singleton('http', fn () => new Illuminate\Http\Client\Factory());
    }

    if ($app->bound('cache') === false && class_exists('Illuminate\Cache\Repository') === true && class_exists('Illuminate\Cache\ArrayStore') === true) {
        $app->singleton('cache', fn () => new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
    }

    if ($app->bound('responsecache') === false) {
        if (class_exists('Fleetbase\TestSupport\ResponseCacheManager') === false) {
            require_once __DIR__ . '/test-support/ResponseCacheManager.php';
        }

        $app->singleton('responsecache', fn () => new Fleetbase\TestSupport\ResponseCacheManager());
    }

    // Crypt (used by SecretCipher) needs an app key and the encrypter binding.
    $testAppKey = random_bytes(32);
    if ($app->bound('encrypter') === false && class_exists('Illuminate\Encryption\Encrypter') === true) {
        $app->singleton('encrypter', fn () => new Illuminate\Encryption\Encrypter($testAppKey, 'AES-256-CBC'));
    }

    if ($app->bound('events') === false && class_exists('Illuminate\Events\Dispatcher') === true) {
        $app->singleton('events', fn () => new Illuminate\Events\Dispatcher($app));
    }

    if ($app->bound('config') === false && class_exists('Illuminate\Config\Repository') === true) {
        $app->singleton('config', fn () => new Illuminate\Config\Repository([
            'app'       => ['url' => 'https://api.example.test', 'key' => 'base64:' . base64_encode($testAppKey)],
            'api'       => ['cache' => ['enabled' => false]],
            'fleetbase' => ['connection' => ['db' => 'testing']],
        ]));
    }

    if ($app->bound('log') === false && class_exists('Psr\Log\NullLogger') === true) {
        if (class_exists('Fleetbase\TestSupport\LoggerManager') === false) {
            require_once __DIR__ . '/test-support/LoggerManager.php';
        }

        $app->singleton('log', fn () => new Fleetbase\TestSupport\LoggerManager());
    }

    if ($app->bound('router') === false) {
        if (class_exists('Fleetbase\TestSupport\RouteRegistrar') === false) {
            require_once __DIR__ . '/test-support/RouteRegistrar.php';
        }

        $app->singleton('router', fn () => new Fleetbase\TestSupport\RouteRegistrar());
    }

    if (
        $app->bound('db') === false
        && class_exists('Illuminate\Database\Capsule\Manager') === true
        && class_exists('Illuminate\Database\Eloquent\Model') === true
        && in_array('sqlite', PDO::getAvailableDrivers(), true) === true
    ) {
        $database = new Illuminate\Database\Capsule\Manager();
        $database->addConnection([
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ], 'sqlite');
        $database->getDatabaseManager()->setDefaultConnection('sqlite');
        $database->setAsGlobal();
        $database->bootEloquent();
        $app->instance('db', $database->getDatabaseManager());
        $app->instance('db.schema', $database->getDatabaseManager()->connection()->getSchemaBuilder());
    }
}

if (function_exists('url') === false) {
    function url(?string $path = null, mixed $parameters = [], ?bool $secure = null): string
    {
        $base = $secure === false ? 'http://api.example.test' : 'https://api.example.test';

        return rtrim($base, '/') . '/' . ltrim((string) $path, '/');
    }
}

if (function_exists('response') === false) {
    function response(): object
    {
        return new class {
            public function json(mixed $data = [], int $status = 200, array $headers = []): Illuminate\Http\JsonResponse
            {
                return new Illuminate\Http\JsonResponse($data, $status, $headers);
            }
        };
    }
}

if (function_exists('abort') === false) {
    function abort(int $code, string $message = '', array $headers = []): never
    {
        throw new Symfony\Component\HttpKernel\Exception\HttpException($code, $message, null, $headers);
    }
}

if (function_exists('event') === false) {
    function event(object|string $event, mixed $payload = [], bool $halt = false): mixed
    {
        $container = function_exists('app') === true ? app() : null;
        if (is_object($container) === true && method_exists($container, 'bound') === true && $container->bound('events') === true) {
            return $container->make('events')->dispatch($event, $payload, $halt);
        }

        if (is_object($event) === true && class_exists('Fleetbase\TestSupport\EventRecorder') === true) {
            Fleetbase\TestSupport\EventRecorder::record($event);
        }

        return $event;
    }
}

if (function_exists('app') === false) {
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        if (class_exists('Illuminate\Container\Container') === true) {
            $container = Illuminate\Container\Container::getInstance();

            return $abstract === null ? $container : $container->make($abstract, $parameters);
        }

        return $abstract === null ? null : new $abstract(...array_values($parameters));
    }
}

if (function_exists('request') === false) {
    function request(?string $key = null, mixed $default = null): mixed
    {
        $request = class_exists('Illuminate\Http\Request') === true ? Illuminate\Http\Request::create('/') : new stdClass();

        return $key === null ? $request : $default;
    }
}

if (function_exists('session') === false) {
    function session(array|string|null $key = null, mixed $default = null): mixed
    {
        static $values = [];

        if (is_array($key) === true) {
            $values = array_merge($values, $key);

            return null;
        }

        if ($key !== null) {
            return $values[$key] ?? $default;
        }

        return new class($values) {
            public function __construct(private array $values)
            {
            }

            public function missing(string $key): bool
            {
                return array_key_exists($key, $this->values) === false;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->values);
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }
        };
    }
}

if (function_exists('now') === false && class_exists('Illuminate\Support\Carbon') === true) {
    function now($tz = null): Illuminate\Support\Carbon
    {
        return Illuminate\Support\Carbon::now($tz);
    }
}

if (trait_exists('Illuminate\Foundation\Auth\Access\AuthorizesRequests') === false) {
    require_once __DIR__ . '/test-support/AuthorizesRequests.php';
}

if (class_exists('Illuminate\Foundation\Auth\User') === false) {
    require_once __DIR__ . '/test-support/AuthUser.php';
}

if (class_exists('Fleetbase\Models\Customer') === false && class_exists('Illuminate\Database\Eloquent\Model') === true) {
    require_once __DIR__ . '/test-support/Customer.php';
}

if (class_exists('Illuminate\Pagination\Paginator') === false) {
    require_once __DIR__ . '/test-support/Paginator.php';
}

if (trait_exists('Illuminate\Foundation\Bus\Dispatchable') === false) {
    require_once __DIR__ . '/test-support/BusDispatchable.php';
}

if (trait_exists('Illuminate\Foundation\Bus\DispatchesJobs') === false) {
    require_once __DIR__ . '/test-support/DispatchesJobs.php';
}

if (trait_exists('Illuminate\Foundation\Events\Dispatchable') === false) {
    if (class_exists('Fleetbase\TestSupport\EventRecorder') === false) {
        require_once __DIR__ . '/test-support/EventRecorder.php';
    }

    require_once __DIR__ . '/test-support/EventsDispatchable.php';
}

if (trait_exists('Illuminate\Foundation\Validation\ValidatesRequests') === false) {
    require_once __DIR__ . '/test-support/ValidatesRequests.php';
}

if (class_exists('Illuminate\Foundation\Http\FormRequest') === false && class_exists('Illuminate\Http\Request') === true) {
    require_once __DIR__ . '/test-support/FormRequest.php';
}

if (interface_exists('Fleetbase\Ai\Contracts\AIContextCapabilityInterface') === false) {
    require_once __DIR__ . '/test-support/AIContextCapabilityInterface.php';
}

if (interface_exists('Fleetbase\Ai\Contracts\AIActionCapabilityInterface') === false) {
    require_once __DIR__ . '/test-support/AIActionCapabilityInterface.php';
}

if (class_exists('Fleetbase\Ai\Models\AiTask') === false) {
    require_once __DIR__ . '/test-support/AiTask.php';
}

if (class_exists('Fleetbase\Ai\Support\Capabilities\AbstractAICapability') === false) {
    require_once __DIR__ . '/test-support/AbstractAICapability.php';
}

if (class_exists('Fleetbase\Ai\Support\AiQueryableResource') === false) {
    require_once __DIR__ . '/test-support/AiQueryableResource.php';
}

if (class_exists('Fleetbase\Ai\Support\AiQueryRegistry') === false) {
    require_once __DIR__ . '/test-support/AiQueryRegistry.php';
}

if (class_exists('Fleetbase\Ai\Support\AiRelativeDateResolver') === false && class_exists('Illuminate\Support\Carbon') === true) {
    require_once __DIR__ . '/test-support/AiRelativeDateResolver.php';
}

set_error_handler(function (int $severity, string $message): bool {
    if (str_contains($message, '/pestphp/pest/vendor/autoload.php') === true) {
        return true;
    }

    return false;
}, E_WARNING);
