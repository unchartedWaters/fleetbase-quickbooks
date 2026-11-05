<?php

declare(strict_types=1);

/*
 * Run inside the Fleetbase API container while Octane serves the API. Creates an organization
 * and an administrator who signs in with RELEASE_CHECK_EMAIL and RELEASE_CHECK_PASSWORD, signs
 * in through Fleetbase's own login, and calls the extension's routes through Octane the way the
 * console does. Each call is made twice, because Octane keeps the app between requests.
 * RELEASE_CHECK_API_URL overrides http://127.0.0.1:8000. Exits 1 if any check failed.
 */

use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Quickbooks\ReleaseCheck\Report;
use Illuminate\Support\Facades\Http;

$app = require __DIR__ . '/boot.php';
require_once __DIR__ . '/Report.php';

$env = static function (string $name, string $default = ''): string {
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
};

$base     = rtrim($env('RELEASE_CHECK_API_URL', 'http://127.0.0.1:8000'), '/');
$email    = $env('RELEASE_CHECK_EMAIL');
$password = $env('RELEASE_CHECK_PASSWORD');
if ($email === '' || $password === '') {
    fwrite(STDERR, "Set RELEASE_CHECK_EMAIL and RELEASE_CHECK_PASSWORD.\n");
    exit(1);
}

$company = Company::create([
    'name'                    => 'Release Check Console',
    'currency'                => 'USD',
    'country'                 => 'US',
    'timezone'                => 'UTC',
    'onboarding_completed_at' => now(),
]);
// password and type are guarded against mass assignment, so they are filled directly.
$user = new User();
$user->forceFill([
    'name'         => 'Release Check Admin',
    'email'        => $email,
    'password'     => $password,
    'company_uuid' => $company->uuid,
    'type'         => 'admin',
    'status'       => 'active',
])->save();
$user->assignCompany($company, 'Administrator');
$company->forceFill(['owner_uuid' => $user->uuid, 'onboarding_completed_by_uuid' => $user->uuid])->save();

$report = new Report();
$api    = static fn () => Http::baseUrl($base . '/quickbooks/int/v1')->acceptJson()->timeout(30);
$status = static fn ($response): string => 'HTTP ' . $response->status() . ' '
    . substr((string) $response->body(), 0, 300);

$health = Http::timeout(10)->get($base . '/health');
$report->check('Fleetbase answers /health', $health->ok() ? [] : [$status($health)]);

$login = Http::acceptJson()->post($base . '/int/v1/auth/login', ['identity' => $email, 'password' => $password]);
$token = (string) $login->json('token');
$report->check('the administrator signs in', $token !== '' ? [] : [$status($login)]);

$anonymous = $api()->get('connection');
$report->check('routes need a signed-in user', $anonymous->status() === 401 ? [] : [$status($anonymous)]);

$unsigned = $api()->post('webhooks', ['eventNotifications' => []]);
$report->check('an unsigned webhook is refused', $unsigned->status() === 401 ? [] : [$status($unsigned)]);

// Intuit returns the browser to the console's QuickBooks page, so finishing a connection needs a signed-in user.
$complete = $api()->post('oauth/complete', ['state' => 'smoke', 'code' => 'smoke', 'realmId' => '123456789']);
$report->check(
    'finishing an OAuth connection needs a signed-in user',
    $complete->status() === 401 ? [] : [$status($complete)]
);

$expected = ['connection' => 'connection', 'settings' => 'sync', 'summary' => 'queue', 'batches' => 'batches'];
foreach ([1, 2] as $round) {
    foreach ($expected as $path => $key) {
        $response = $api()->withToken($token)->get($path);
        $answered = $response->ok() && $response->json($key, 'missing') !== 'missing';
        $report->check("GET {$path} (request {$round})", $answered ? [] : [$status($response)]);
    }
}

$sync  = (array) $api()->withToken($token)->get('settings')->json('sync');
$size  = ((int) ($sync['batch_size'] ?? 100)) === 50 ? 60 : 50;
$saved = $api()->withToken($token)->post('settings', ['sync' => array_merge($sync, ['batch_size' => $size])]);
$after = (int) $api()->withToken($token)->get('settings')->json('sync.batch_size');
$report->check('organization sync settings save and read back', $saved->ok() && $after === $size
    ? []
    : [$status($saved) . ' batch_size now ' . $after]);

exit($report->exitCode());
