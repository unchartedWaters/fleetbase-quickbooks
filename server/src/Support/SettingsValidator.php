<?php

namespace Fleetbase\Quickbooks\Support;

class SettingsValidator
{
    /**
     * @param array<string, mixed> $auth
     * @param array<string, mixed> $sync
     *
     * @return array<string, string>
     */
    public function errors(array $auth, array $sync): array
    {
        $errors = [];
        if (trim((string) ($auth['client_id'] ?? '')) === '') {
            $errors['client_id'] = 'Enter the Client ID from developer.intuit.com, your app, Keys & credentials.';
        }

        $redirect = trim((string) ($auth['redirect_uri'] ?? ''));
        $schemeOk = str_starts_with($redirect, 'https://') || str_starts_with($redirect, 'http://');
        if (filter_var($redirect, FILTER_VALIDATE_URL) === false || !$schemeOk) {
            $errors['redirect_uri'] = 'Enter a full http:// or https:// address that is listed under Keys & credentials, Redirect URIs.';
        }

        $environment = (string) ($auth['environment'] ?? '');
        if (!in_array($environment, ['sandbox', 'production'], true)) {
            $errors['environment'] = 'Choose Sandbox for Development keys, or Production for the live organization.';
        }

        if (trim((string) ($auth['client_secret'] ?? '')) === '') {
            $errors['client_secret'] = 'Enter the Client secret shown next to the Client ID on Keys & credentials.';
        }

        $this->requireInt($errors, $sync, 'interval_minutes', 1, 'Enter a whole number of minutes, at least 1.');
        $this->requireInt($errors, $sync, 'periodic_interval_hours', 1, 'Enter a whole number of hours, at least 1.');
        // A missing batch_size stays stored. A sent value is the query limit, from 1 to 100.
        if (array_key_exists('batch_size', $sync)) {
            $this->requireBoundedInt($errors, $sync, 'batch_size', 1, 100, 'Enter a whole number from 1 to 100.');
        }
        $this->requireInt($errors, $sync, 'retry_limit', 1, 'Enter a whole number of retries, at least 1.');
        $this->requireInt($errors, $sync, 'default_backoff_seconds', 5, 'Enter a whole number of seconds, at least 5.');

        $entities = [
            'customer' => 'Customers',
            'invoice'  => 'Invoices',
            'payment'  => 'Payments',
            'wallet'   => 'Accounts / Wallets',
        ];
        foreach ($entities as $entity => $label) {
            if (!$this->entityEnabled($sync, $entity)) {
                continue;
            }
            $this->requireChoice($errors, $sync, $entity . '_conflict', ['fleetbase', 'quickbooks'], 'Choose Fleetbase or QuickBooks as Primary for ' . $label . '.');
            $this->requireChoice($errors, $sync, $entity . '_reference', ['fleetbase', 'quickbooks'], 'Choose Fleetbase or QuickBooks as Primary for ' . $label . '.');
            $this->requireDirection($errors, $sync, $entity . '_direction', 'Choose Both, Outbound, or Inbound for ' . $label . '.');
        }

        return $errors;
    }

    /**
     * A stored Off or a missing direction means Both. Primary and direction are
     * required only while that entity's enable flag is on.
     *
     * @param array<string, mixed> $sync
     */
    private function entityEnabled(array $sync, string $entity): bool
    {
        $key = $entity . '_enabled';
        if (!array_key_exists($key, $sync)) {
            return true;
        }
        $value = $sync[$key];
        if ($value === null || $value === '') {
            return true;
        }
        if (is_bool($value)) {
            return $value;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? true;
    }

    /**
     * A stored Off or a missing direction means Both.
     *
     * @param array<string, string> $errors
     * @param array<string, mixed>  $sync
     */
    private function requireDirection(array &$errors, array $sync, string $field, string $message): void
    {
        $value = $sync[$field] ?? null;
        if ($value === null || $value === '' || $value === 'off') {
            return;
        }
        if (is_string($value) && in_array($value, ['both', 'outbound', 'inbound'], true)) {
            return;
        }

        $errors[$field] = $message;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $sync
     */
    private function requireInt(array &$errors, array $sync, string $field, int $minimum, string $message): void
    {
        $value = $sync[$field] ?? null;
        $whole = is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
        if ($whole && (int) $value >= $minimum) {
            return;
        }

        $errors[$field] = $message;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $sync
     */
    private function requireBoundedInt(array &$errors, array $sync, string $field, int $minimum, int $maximum, string $message): void
    {
        $value = $sync[$field] ?? null;
        $whole = is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
        if ($whole && (int) $value >= $minimum && (int) $value <= $maximum) {
            return;
        }

        $errors[$field] = $message;
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $sync
     * @param array<int, string>    $allowed
     */
    private function requireChoice(array &$errors, array $sync, string $field, array $allowed, string $message): void
    {
        $value = $sync[$field] ?? null;
        if (is_string($value) && in_array($value, $allowed, true)) {
            return;
        }

        $errors[$field] = $message;
    }
}
