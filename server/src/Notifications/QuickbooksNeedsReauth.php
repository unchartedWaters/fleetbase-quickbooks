<?php

namespace Fleetbase\Quickbooks\Notifications;

use Illuminate\Notifications\Notification;

class QuickbooksNeedsReauth extends Notification
{
    public string $name = 'QuickBooks needs reauthorization';

    public string $description = 'A QuickBooks connection could not refresh its token and must be connected again.';

    public string $package = 'quickbooks';

    /**
     * @var array<int, string>
     */
    public array $notificationOptions = ['database'];

    public function __construct(public string $companyUuid, public string $realmId)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'company_uuid' => $this->companyUuid,
            'realm_id'     => $this->realmId,
            'message'      => 'QuickBooks needs to be reconnected before sync can continue.',
        ];
    }
}
