<?php

use Fleetbase\Models\User;
use Fleetbase\Quickbooks\Notifications\QuickbooksNeedsReauth;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

test('the company owner is told once an hour when quickbooks needs reauthorization', function () {
    Notification::fake();
    Cache::forget('quickbooks.reauth-notified.company-uuid');

    $owner       = new User();
    $owner->uuid = 'owner-uuid';

    $directory = new class($owner) extends FleetbaseDirectory {
        public function __construct(private User $owner)
        {
        }

        /** @param array<string, mixed> $connection */
        public function tell(array $connection): void
        {
            $this->notifyReauth($connection);
        }

        protected function reauthRecipients(string $companyUuid): array
        {
            return [$this->owner];
        }
    };

    $connection = ['company_uuid' => 'company-uuid', 'realm_id' => 'realm', 'needs_reauth' => true];
    $directory->tell($connection);
    $directory->tell($connection);

    Notification::assertSentToTimes($owner, QuickbooksNeedsReauth::class, 1);
    expect(Cache::has('quickbooks.reauth-notified.company-uuid'))->toBeTrue();
});
