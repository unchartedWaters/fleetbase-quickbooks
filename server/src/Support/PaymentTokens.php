<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * One QuickBooks payment can pay several invoices, and each Fleetbase invoice syncs it on its
 * own. The payment link keeps, by Fleetbase invoice uuid, the payment SyncToken each invoice
 * last synced (a JSON object in the invoice_tokens column). Syncing one invoice then does not
 * hide a QuickBooks change of the payment from the others.
 */
class PaymentTokens
{
    public const COLUMN = 'invoice_tokens';

    /**
     * The tokens by invoice uuid. A link that holds none gives [].
     *
     * @param array<string, mixed>|null $link
     *
     * @return array<string, string>
     */
    public static function all(?array $link): array
    {
        $stored = $link[self::COLUMN] ?? null;
        if (is_string($stored) === true && $stored !== '') {
            $stored = json_decode($stored, true);
        }
        if (is_array($stored) === false) {
            return [];
        }
        $tokens = [];
        foreach ($stored as $invoiceUuid => $token) {
            if (is_string($invoiceUuid) === true && (is_string($token) === true || is_int($token) === true)) {
                $tokens[$invoiceUuid] = (string) $token;
            }
        }

        return $tokens;
    }

    /**
     * The link as $invoiceUuid last synced it. Once the link holds a token for any invoice, an
     * invoice it holds none for has not synced this payment yet, so it gets no token. A link
     * that holds none keeps its own SyncToken.
     *
     * @param array<string, mixed>|null $link
     *
     * @return array<string, mixed>|null
     */
    public static function forInvoice(?array $link, string $invoiceUuid): ?array
    {
        $tokens = self::all($link);
        if ($link === null || $tokens === []) {
            return $link;
        }
        $link['sync_token'] = $tokens[$invoiceUuid] ?? '';

        return $link;
    }

    /**
     * The column value once $invoiceUuid synced the payment at $token.
     *
     * @param array<string, mixed>|null $link
     */
    public static function with(?array $link, string $invoiceUuid, string $token): string
    {
        $tokens = self::all($link);
        if ($invoiceUuid !== '') {
            $tokens[$invoiceUuid] = $token;
        }
        ksort($tokens);

        return (string) json_encode($tokens, JSON_UNESCAPED_SLASHES);
    }
}
