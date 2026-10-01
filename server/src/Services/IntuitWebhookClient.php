<?php

namespace Fleetbase\Quickbooks\Services;

/**
 * Intuit sets the webhook endpoint URL, the entity list, and the verifier token
 * in the developer portal (Webhooks, then Production or Development). The
 * configure-webhooks documentation does not publish an API to set that URL,
 * choose entities, or unsubscribe a realm. This class does not call Intuit.
 */
class IntuitWebhookClient
{
}
