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
        return array_merge($this->credentialErrors($auth), $this->syncErrors($sync));
    }

    /**
     * The global Intuit credentials. Organization sync settings never carry these.
     *
     * @param array<string, mixed> $auth
     *
     * @return array<string, string>
     */
    public function credentialErrors(array $auth): array
    {
        $errors = [];
        if (trim((string) ($auth['client_id'] ?? '')) === '') {
            $errors['client_id'] = 'quickbooks.validation.client-id';
        }

        $redirect = trim((string) ($auth['redirect_uri'] ?? ''));
        $schemeOk = str_starts_with($redirect, 'https://') === true || str_starts_with($redirect, 'http://') === true;
        if (filter_var($redirect, FILTER_VALIDATE_URL) === false || $schemeOk === false) {
            $errors['redirect_uri'] = 'quickbooks.validation.redirect-uri';
        }

        $environment = (string) ($auth['environment'] ?? '');
        if (in_array($environment, ['sandbox', 'production'], true) === false) {
            $errors['environment'] = 'quickbooks.validation.environment';
        }

        if (trim((string) ($auth['client_secret'] ?? '')) === '') {
            $errors['client_secret'] = 'quickbooks.validation.client-secret';
        }

        return $errors;
    }

    /**
     * The sync options, for the global template and for an organization's own copy alike.
     *
     * @param array<string, mixed> $sync
     *
     * @return array<string, string>
     */
    public function syncErrors(array $sync): array
    {
        $errors = [];
        $this->requireInt($errors, $sync, 'interval_minutes', 1, 'quickbooks.validation.interval-minutes');
        $hours = 'quickbooks.validation.periodic-interval-hours';
        $this->requireInt($errors, $sync, 'periodic_interval_hours', 1, $hours);
        // A missing batch_size stays stored. A sent value is the query limit, from 1 to 100.
        if (array_key_exists('batch_size', $sync) === true) {
            $this->requireBoundedInt($errors, $sync, 'batch_size', 1, 100, 'quickbooks.validation.batch-size');
        }
        $this->requireInt($errors, $sync, 'retry_limit', 1, 'quickbooks.validation.retry-limit');
        $this->requireInt(
            $errors,
            $sync,
            'default_backoff_seconds',
            5,
            'quickbooks.validation.retry-delay'
        );

        // Each message is a translation key; the controller renders it in the reader's locale.
        $entities = [
            'customer' => ['quickbooks.validation.primary-customer', 'quickbooks.validation.direction-customer'],
            'invoice'  => ['quickbooks.validation.primary-invoice', 'quickbooks.validation.direction-invoice'],
            'payment'  => ['quickbooks.validation.primary-payment', 'quickbooks.validation.direction-payment'],
            'wallet'   => ['quickbooks.validation.primary-wallet', 'quickbooks.validation.direction-wallet'],
        ];
        foreach ($entities as $entity => [$primary, $direction]) {
            if ($this->entityEnabled($sync, $entity) === false) {
                continue;
            }
            $this->requireChoice($errors, $sync, $entity . '_conflict', ['fleetbase', 'quickbooks'], $primary);
            $this->requireChoice($errors, $sync, $entity . '_reference', ['fleetbase', 'quickbooks'], $primary);
            $this->requireDirection($errors, $sync, $entity . '_direction', $direction);
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
        if (array_key_exists($key, $sync) === false) {
            return true;
        }
        $value = $sync[$key];
        if ($value === null || $value === '') {
            return true;
        }
        if (is_bool($value) === true) {
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
        if (is_string($value) === true && in_array($value, ['both', 'outbound', 'inbound'], true) === true) {
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
        $whole = is_int($value) === true || (is_string($value) === true && preg_match('/^-?\d+$/', $value) === 1);
        if ($whole === true && (int) $value >= $minimum) {
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
        $whole = is_int($value) === true || (is_string($value) === true && preg_match('/^-?\d+$/', $value) === 1);
        if ($whole === true && (int) $value >= $minimum && (int) $value <= $maximum) {
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
        if (is_string($value) === true && in_array($value, $allowed, true) === true) {
            return;
        }

        $errors[$field] = $message;
    }
}
