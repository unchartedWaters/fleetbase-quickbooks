<?php

use Fleetbase\Quickbooks\Jobs\ApplyRemoteChange;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\WebhookRetry;
use Fleetbase\Quickbooks\Tests\Support\RemoteChangeDirectory;
use Fleetbase\Quickbooks\Tests\Support\RemoteChangeEngine;

/*
 * Intuit does not send a webhook again once it got HTTP 200, so a remote change whose
 * QuickBooks read fails for a temporary reason is queued again here instead of dropped.
 */

test('a remote change refused with a 429 waits for retry-after and stores the rate limit', function () {
    $engine    = RemoteChangeEngine::failingWith(new QuickBooksException(429, qbRequestFailed(429), '120'));
    $directory = new RemoteChangeDirectory();
    $job       = new ApplyRemoteChange('company-uuid', [
        ['entity' => 'Customer', 'id' => '1', 'operation' => 'Update'],
    ]);
    $before    = time();

    $jobs       = $engine->run($job, $directory);
    $connection = $directory->saved[0]->connections['company-uuid'];

    expect($engine->calls)->toHaveCount(1)
        ->and($jobs)->toHaveCount(1)
        ->and($jobs[0])->toBeInstanceOf(ApplyRemoteChange::class)
        ->and($jobs[0]->entities)->toBe($job->entities)
        ->and($jobs[0]->quickbooksAttempt)->toBe(1)
        ->and($jobs[0]->lockAttempt)->toBe(0)
        ->and($jobs[0]->delay)->toBeGreaterThanOrEqual(120)
        ->and($jobs[0]->delay)->toBeLessThanOrEqual(132)
        ->and($connection['last_rate_limit_wait'])->toBe(120)
        ->and($connection['rate_limited_until'])->toBeGreaterThanOrEqual($before + 120)
        ->and($directory->batches())->toBe([]);
});

test('a remote change that quickbooks could not answer is queued again with a growing wait', function (int $status) {
    $engine    = RemoteChangeEngine::failingWith(new QuickBooksException($status, qbRequestFailed($status)));
    $directory = new RemoteChangeDirectory();
    $first     = new ApplyRemoteChange('company-uuid', [['entity' => 'Invoice', 'id' => '3', 'operation' => 'Update']]);
    $later     = new ApplyRemoteChange('company-uuid', $first->entities, 1);

    $later->quickbooksAttempt = 2;
    $firstJobs                = $engine->run($first, $directory);
    $laterJobs                = $engine->run($later, $directory);

    expect($firstJobs)->toHaveCount(1)
        ->and($firstJobs[0]->quickbooksAttempt)->toBe(1)
        ->and($firstJobs[0]->delay)->toBeGreaterThanOrEqual(WebhookRetry::BASE_DELAY_SECONDS)
        ->and($firstJobs[0]->delay)->toBeLessThanOrEqual(WebhookRetry::BASE_DELAY_SECONDS + 3)
        ->and($laterJobs)->toHaveCount(1)
        ->and($laterJobs[0]->quickbooksAttempt)->toBe(3)
        ->and($laterJobs[0]->lockAttempt)->toBe(1)
        ->and($laterJobs[0]->delay)->toBeGreaterThanOrEqual(WebhookRetry::BASE_DELAY_SECONDS * 4)
        ->and($laterJobs[0]->delay)->toBeLessThanOrEqual(WebhookRetry::BASE_DELAY_SECONDS * 4 + 12)
        ->and($directory->saved[0]->connections['company-uuid'])->not->toHaveKey('rate_limited_until')
        ->and($directory->batches())->toBe([]);
})->with(['service unavailable' => 503, 'request timeout' => 408, 'no response' => 0]);

test('a remote change that still fails after the last retry is recorded as a failed sync', function () {
    $engine    = RemoteChangeEngine::failingWith(new QuickBooksException(503, 'QuickBooks is unavailable.'));
    $directory = new RemoteChangeDirectory();
    $job       = new ApplyRemoteChange('company-uuid', [
        ['entity' => 'Customer', 'id' => '1', 'operation' => 'Update'],
    ]);

    $job->quickbooksAttempt = WebhookRetry::LIMIT;
    $jobs                   = $engine->run($job, $directory);
    $batches                = $directory->batches();
    $attempts               = $directory->attempts();

    expect($jobs)->toBe([])
        ->and($batches)->toHaveCount(1)
        ->and($batches[0]['trigger'])->toBe('webhook')
        ->and($batches[0]['direction'])->toBe('inbound')
        ->and($batches[0]['status'])->toBe('failed')
        ->and($batches[0]['failed'])->toBe(1)
        ->and($attempts)->toHaveCount(1)
        ->and($attempts[0]['outcome'])->toBe('failed')
        ->and(qbText($attempts[0]['error']))->toBe(
            'Fleetbase could not read a change from QuickBooks after several tries, so it was not applied.'
            . ' Save the record in QuickBooks again to send it again. QuickBooks is unavailable.'
        );
});

test('a remote change is not read from quickbooks while the connection is rate limited', function () {
    $engine    = RemoteChangeEngine::failingWith(null);
    $directory = new RemoteChangeDirectory(['rate_limited_until' => time() + 300, 'last_rate_limit_wait' => 300]);
    $job       = new ApplyRemoteChange('company-uuid', [
        ['entity' => 'Customer', 'id' => '1', 'operation' => 'Update'],
    ]);

    $jobs = $engine->run($job, $directory);

    expect($engine->calls)->toBe([])
        ->and($directory->saved)->toBe([])
        ->and($jobs)->toHaveCount(1)
        ->and($jobs[0]->quickbooksAttempt)->toBe(1)
        ->and($jobs[0]->delay)->toBeGreaterThanOrEqual(299)
        ->and($jobs[0]->delay)->toBeLessThanOrEqual(331);
});

test('a remote change refused for a reason a retry does not fix still fails the job', function () {
    $engine    = RemoteChangeEngine::failingWith(new QuickBooksException(400, qbRequestFailed(400)));
    $directory = new RemoteChangeDirectory();
    $job       = new ApplyRemoteChange('company-uuid', [
        ['entity' => 'Customer', 'id' => '1', 'operation' => 'Update'],
    ]);
    $jobs      = null;

    expect(function () use ($engine, $job, $directory, &$jobs) {
        $jobs = $engine->run($job, $directory);
    })->toThrow(QuickBooksException::class);
    expect($jobs)->toBeNull()
        ->and($directory->saved)->toBe([]);
});

test('a remote change queued before retries were counted starts at the first retry', function () {
    // The payload a job queued by the previous release carries: it has no retry counter.
    $old = 'O:43:"Fleetbase\\Quickbooks\\Jobs\\ApplyRemoteChange":3:{s:11:"companyUuid";s:12:"company-uuid";'
        . 's:8:"entities";a:0:{}s:11:"lockAttempt";i:2;}';
    $revived = unserialize($old);

    expect($revived)->toBeInstanceOf(ApplyRemoteChange::class)
        ->and($revived->lockAttempt)->toBe(2)
        ->and($revived->quickbooksAttempt)->toBe(0);
});

test('a queued retry wakes a little after the wait,'
. ' so jobs held by one rate limit do not all call quickbooks at once', function () {
    $draws = [];
    for ($index = 0; $index < 50; $index++) {
        $draws[] = WebhookRetry::spread(300);
    }

    expect(min($draws))->toBeGreaterThanOrEqual(300)
        ->and(max($draws))->toBeLessThanOrEqual(330)
        ->and(count(array_unique($draws)))->toBeGreaterThan(1)
        ->and(WebhookRetry::spread(0))->toBe(0)
        ->and(WebhookRetry::spread(5))->toBeLessThanOrEqual(6);
});
