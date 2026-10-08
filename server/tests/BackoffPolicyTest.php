<?php

use Fleetbase\Quickbooks\Support\BackoffPolicy;

test('default wait doubles from the retry delay and caps at 900 seconds', function () {
    $policy = new BackoffPolicy(static fn (int $wait): int => $wait + 50);

    expect($policy->defaultWait(30, 1))->toBe(30)
        ->and($policy->defaultWait(30, 2))->toBe(60)
        ->and($policy->defaultWait(30, 3))->toBe(120)
        ->and($policy->defaultWait(30, 4))->toBe(240)
        ->and($policy->defaultWait(500, 1))->toBe(500)
        ->and($policy->defaultWait(500, 2))->toBe(900)
        ->and($policy->defaultWait(30, 8))->toBe(900);
});

test('a rate limit uses Retry-After when present and doubles only when it is absent', function () {
    $policy = new BackoffPolicy(static fn (int $wait): int => $wait);

    expect($policy->rateLimitWait('45', null))->toBe(45)
        ->and($policy->rateLimitWait(null, 45))->toBe(90)
        ->and($policy->rateLimitWait('10', 45))->toBe(10)
        ->and($policy->rateLimitWait(null, null))->toBe(60);
});
