<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * Checks Intuit's intuit-signature header.
 *
 * The header is base64(HMAC-SHA256(raw request body, verifier token)).
 */
class WebhookSignature
{
    /**
     * True when the signature matches one verifier for a single connection.
     * An empty list rejects. The caller decides which verifiers belong to that
     * connection. Comparison is hash_equals on the base64 HMAC-SHA256.
     *
     * @param array<int, string> $verifiers
     */
    public function accepts(string $rawBody, ?string $signature, array $verifiers): bool
    {
        if (is_string($signature) === false || $signature === '') {
            return false;
        }

        foreach ($verifiers as $verifier) {
            if (is_string($verifier) === false || $verifier === '') {
                continue;
            }
            $computed = base64_encode(hash_hmac('sha256', $rawBody, $verifier, true));
            if (hash_equals($computed, $signature) === true) {
                return true;
            }
        }

        return false;
    }
}
