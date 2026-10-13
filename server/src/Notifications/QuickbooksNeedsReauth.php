<?php

namespace Fleetbase\Quickbooks\Notifications;

use Fleetbase\Quickbooks\Support\Translation;
use Illuminate\Notifications\Notification;

class QuickbooksNeedsReauth extends Notification
{
    public string $name = 'QuickBooks needs reauthorization';

    public string $description = 'Sent when QuickBooks no longer accepts an organization\'s connection and it must be '
        . 'connected again.';

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
            'message'      => Translation::get('quickbooks.messages.reauth', [], $this->recipientLocale($notifiable)),
        ];
    }

    /**
     * The recipient's console locale. A Fleetbase user has one; anything else reads en-us.
     */
    private function recipientLocale(object $notifiable): string
    {
        try {
            return method_exists($notifiable, 'getLocale') === true
                ? Translation::normalize((string) $notifiable->getLocale())
                : Translation::FALLBACK_LOCALE;
        } catch (\Throwable) {
            return Translation::FALLBACK_LOCALE;
        }
    }
}
