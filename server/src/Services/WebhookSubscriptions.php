<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Http\Controllers\SettingController;

/**
 * Intuit webhooks are configured in the developer portal, not by Fleetbase.
 * apply() does not call Intuit. The endpoint is the computed receiver URL.
 */
class WebhookSubscriptions
{
    /**
     * OAuth complete and settings save call this with the settings scope and company.
     * There is no Intuit subscription API, so this does not register the receiver URL.
     */
    public function apply(string $scope, string $companyUuid): void
    {
    }

    /**
     * The URL the operator pastes into the Intuit app Endpoint URL.
     * It is the application server URL plus /quickbooks/int/v1/webhooks.
     * A stored webhook_url is not used.
     */
    public function webhookUrl(string $companyUuid): string
    {
        return SettingController::publicReceiverUrl();
    }
}
