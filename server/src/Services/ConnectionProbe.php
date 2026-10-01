<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Support\QuickBooksException;

class ConnectionProbe
{
    public function __construct(private QuickBooksClient $client, private ?ConnectionTokens $tokens = null)
    {
    }

    /**
     * @param array<string, mixed>|null $connection
     *
     * @return array{ok: bool, message: string, company_name: string|null, home_currency: string|null}
     */
    public function probe(?array $connection): array
    {
        $empty = [
            'ok'            => false,
            'message'       => 'QuickBooks is not connected.',
            'company_name'  => null,
            'home_currency' => null,
        ];
        if (!is_array($connection) || (string) ($connection['realm_id'] ?? '') === '' || (string) ($connection['access_token'] ?? '') === '') {
            return $empty;
        }

        if ($this->tokens !== null) {
            $connection = $this->tokens->refreshIfDue($connection, time());
            $blocked    = ConnectionTokens::blockedMessage($connection, time());
            if ($blocked !== null) {
                $empty['message'] = $blocked;

                return $empty;
            }
        }

        try {
            $info     = $this->client->companyInfo($connection);
            $currency = $this->client->homeCurrency($connection);
        } catch (QuickBooksException $exception) {
            $empty['message'] = $this->failureMessage($exception);

            return $empty;
        }

        return [
            'ok'            => true,
            'message'       => 'QuickBooks connection works.',
            'company_name'  => isset($info['CompanyName']) ? (string) $info['CompanyName'] : null,
            'home_currency' => $currency,
        ];
    }

    private function failureMessage(QuickBooksException $exception): string
    {
        if ($exception->status === 0 || $this->looksLikeTransportFailure($exception->getMessage())) {
            return QuickBooksClient::TRANSPORT_MESSAGE;
        }

        return $exception->getMessage();
    }

    private function looksLikeTransportFailure(string $message): bool
    {
        return preg_match('/\bcURL\b|curl error|could not resolve host|connection refused|connection timed out|failed to connect|operation timed out|name or service not known/i', $message) === 1;
    }
}
