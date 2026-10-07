<?php

namespace Fleetbase\Quickbooks\Support;

class SyncSettingsResolver
{
    /**
     * Install-wide sync settings, then config defaults. An organization row is unused.
     *
     * @param array<string, mixed> $company
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    public function resolve(array $company, array $admin, array $defaults): array
    {
        unset($company);
        unset($admin['override'], $admin['sources']);

        $enabled  = $this->pickBool('enabled', $admin, (bool) ($defaults['enabled'] ?? true));
        $interval = $this->pickInt('interval_minutes', $admin, (int) ($defaults['interval_minutes'] ?? 5), 1);
        $periodic = $this->pickInt('periodic_interval_hours', $admin, (int) ($defaults['periodic_interval_hours'] ?? 24), 1);
        $batch    = $this->pickInt('batch_size', $admin, (int) ($defaults['batch_size'] ?? 100), 1);
        $retry    = $this->pickInt('retry_limit', $admin, (int) ($defaults['retry_limit'] ?? 5), 1);
        $backoff  = $this->pickInt('default_backoff_seconds', $admin, (int) ($defaults['default_backoff_seconds'] ?? 30), 5);
        $choices  = [
            'customer_conflict'  => $this->pickChoice('customer_conflict', $admin, (string) ($defaults['customer_conflict'] ?? 'fleetbase'), ['fleetbase', 'quickbooks', 'report']),
            'customer_reference' => $this->pickChoice('customer_reference', $admin, (string) ($defaults['customer_reference'] ?? 'fleetbase'), ['fleetbase', 'quickbooks']),
            'invoice_conflict'   => $this->pickChoice('invoice_conflict', $admin, (string) ($defaults['invoice_conflict'] ?? 'fleetbase'), ['fleetbase', 'quickbooks', 'report']),
            'invoice_reference'  => $this->pickChoice('invoice_reference', $admin, (string) ($defaults['invoice_reference'] ?? 'fleetbase'), ['fleetbase', 'quickbooks']),
            'payment_conflict'   => $this->pickChoice('payment_conflict', $admin, (string) ($defaults['payment_conflict'] ?? 'fleetbase'), ['fleetbase', 'quickbooks', 'report']),
            'payment_reference'  => $this->pickChoice('payment_reference', $admin, (string) ($defaults['payment_reference'] ?? 'fleetbase'), ['fleetbase', 'quickbooks']),
            'wallet_conflict'    => $this->pickChoice('wallet_conflict', $admin, (string) ($defaults['wallet_conflict'] ?? 'fleetbase'), ['fleetbase', 'quickbooks', 'report']),
            'wallet_reference'   => $this->pickChoice('wallet_reference', $admin, (string) ($defaults['wallet_reference'] ?? 'fleetbase'), ['fleetbase', 'quickbooks']),
            'customer_direction' => $this->pickDirection('customer_direction', $admin),
            'invoice_direction'  => $this->pickDirection('invoice_direction', $admin),
            'payment_direction'  => $this->pickDirection('payment_direction', $admin),
            'wallet_direction'   => $this->pickDirection('wallet_direction', $admin),
            'customer_enabled'   => $this->pickBool('customer_enabled', $admin, (bool) ($defaults['customer_enabled'] ?? true)),
            'invoice_enabled'    => $this->pickBool('invoice_enabled', $admin, (bool) ($defaults['invoice_enabled'] ?? true)),
            'payment_enabled'    => $this->pickBool('payment_enabled', $admin, (bool) ($defaults['payment_enabled'] ?? true)),
            'wallet_enabled'     => $this->pickBool('wallet_enabled', $admin, (bool) ($defaults['wallet_enabled'] ?? true)),
        ];

        $resolved = [
            'enabled'                 => $enabled['value'],
            'interval_minutes'        => $interval['value'],
            'periodic_interval_hours' => $periodic['value'],
            'batch_size'              => $batch['value'],
            'retry_limit'             => $retry['value'],
            'default_backoff_seconds' => $backoff['value'],
            'sources'                 => [
                'enabled'                 => $enabled['source'],
                'interval_minutes'        => $interval['source'],
                'periodic_interval_hours' => $periodic['source'],
                'batch_size'              => $batch['source'],
                'retry_limit'             => $retry['source'],
                'default_backoff_seconds' => $backoff['source'],
            ],
        ];
        foreach ($choices as $field => $choice) {
            $resolved[$field]            = $choice['value'];
            $resolved['sources'][$field] = $choice['source'];
        }

        return $this->withoutReportWrites($resolved);
    }

    /**
     * @param array<string, mixed> $company
     *
     * @return array{value: bool, source: string}
     */
    private function pickBool(string $field, array $company, bool $default): array
    {
        $value = $this->toBool($company[$field] ?? null);
        if ($value !== null) {
            return ['value' => $value, 'source' => 'admin'];
        }

        return ['value' => $default, 'source' => 'default'];
    }

    private function toBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value) === true) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * @param array<string, mixed> $company
     *
     * @return array{value: int, source: string}
     */
    private function pickInt(string $field, array $company, int $default, int $minimum): array
    {
        if (isset($company[$field]) === true && $company[$field] !== '') {
            return ['value' => max($minimum, (int) $company[$field]), 'source' => 'admin'];
        }

        return ['value' => max($minimum, $default), 'source' => 'default'];
    }

    /**
     * @param array<string, mixed> $company
     * @param array<int, string>   $allowed
     *
     * @return array{value: string, source: string}
     */
    private function pickChoice(string $field, array $company, string $default, array $allowed): array
    {
        $value = $company[$field] ?? null;
        if (is_string($value) === true && in_array($value, $allowed, true) === true) {
            return ['value' => $value, 'source' => 'admin'];
        }

        return ['value' => $default, 'source' => 'default'];
    }

    /**
     * Off and a missing direction sync both ways. A false entity enable flag
     * is what keeps that entity out of sync.
     *
     * @param array<string, mixed> $company
     *
     * @return array{value: string, source: string}
     */
    private function pickDirection(string $field, array $company): array
    {
        if (array_key_exists($field, $company) === false) {
            return ['value' => 'both', 'source' => 'default'];
        }

        $value = $company[$field];
        if ($value === 'off' || $value === null || $value === '') {
            return ['value' => 'both', 'source' => 'admin'];
        }
        if (is_string($value) === true && in_array($value, ['both', 'outbound', 'inbound'], true) === true) {
            return ['value' => $value, 'source' => 'admin'];
        }

        return ['value' => 'both', 'source' => 'default'];
    }

    /**
     * A stored report conflict is not fleetbase. Rewriting it made sync push
     * Fleetbase onto QuickBooks. Direction off is the engine's non-writing
     * signal. Enable flags are left as stored.
     *
     * @param array<string, mixed> $resolved
     *
     * @return array<string, mixed>
     */
    private function withoutReportWrites(array $resolved): array
    {
        foreach (['customer', 'invoice', 'payment', 'wallet'] as $entity) {
            if (($resolved[$entity . '_conflict'] ?? null) !== 'report') {
                continue;
            }
            $resolved[$entity . '_direction'] = 'off';
        }

        return $resolved;
    }
}
