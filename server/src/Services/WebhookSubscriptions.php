<?php

namespace Fleetbase\Quickbooks\Services;

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
}
