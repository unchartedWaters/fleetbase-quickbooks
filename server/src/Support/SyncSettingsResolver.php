<?php

namespace Fleetbase\Quickbooks\Support;

class SyncSettingsResolver
{
    /**
     * Organization sync settings, then config defaults. A stored system row is unused.
     *
     * @param array<string, mixed> $company
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    public function resolve(array $company, array $admin, array $defaults): array
    {
        unset($admin);
        unset($company['override'], $company['sources']);

        $enabled  = $this->pickBool('enabled', $company, (bool) ($defaults['enabled'] ?? true));
        $interval = $this->pickInt('interval_minutes', $company, (int) ($defaults['interval_minutes'] ?? 5), 1);
        $periodic = $this->pickInt('periodic_interval_hours', $company, (int) ($defaults['periodic_interval_hours'] ?? 24), 1);
        $batch    = $this->pickInt('batch_size', $company, (int) ($defaults['batch_size'] ?? 100), 1);
        $retry    = $this->pickInt('retry_limit', $company, (int) ($defaults['retry_limit'] ?? 5), 1);
        $backoff  = $this->pickInt('default_backoff_seconds', $company, (int) ($defaults['default_backoff_seconds'] ?? 30), 5);
        $choices  = [
            'customer_conflict'  => $this->pickChoice('customer_conflict', $company, (string) ($defaults['customer_conflict'] ?? 'fleetbase'), ['fleetbase', 'quickbooks', 'report']),
            'customer_reference' => $this->pickChoice('customer_reference', $company, (string) ($defaults['customer_reference'] ?? 'fleetbase'), ['fleetbase', 'quickbooks']),
            'invoice_conflict'   => $this->pickChoice('invoice_conflict', $company, (string) ($defaults['invoice_conflict'] ?? 'fleetbase'), ['fleetbase', 'quickbooks', 'report']),
            'invoice_reference'  => $this->pickChoice('invoice_reference', $company, (string) ($defaults['invoice_reference'] ?? 'fleetbase'), ['fleetbase', 'quickbooks']),
            'payment_conflict'   => $this->pickChoice('payment_conflict', $company, (string) ($defaults['payment_conflict'] ?? 'fleetbase'), ['fleetbase', 'quickbooks', 'report']),
            'payment_reference'  => $this->pickChoice('payment_reference', $company, (string) ($defaults['payment_reference'] ?? 'fleetbase'), ['fleetbase', 'quickbooks']),
            'wallet_conflict'    => $this->pickChoice('wallet_conflict', $company, (string) ($defaults['wallet_conflict'] ?? 'fleetbase'), ['fleetbase', 'quickbooks', 'report']),
            'wallet_reference'   => $this->pickChoice('wallet_reference', $company, (string) ($defaults['wallet_reference'] ?? 'fleetbase'), ['fleetbase', 'quickbooks']),
            'customer_direction' => $this->pickDirection('customer_direction', $company),
            'invoice_direction'  => $this->pickDirection('invoice_direction', $company),
            'payment_direction'  => $this->pickDirection('payment_direction', $company),
            'wallet_direction'   => $this->pickDirection('wallet_direction', $company),
            'customer_enabled'   => $this->pickBool('customer_enabled', $company, (bool) ($defaults['customer_enabled'] ?? true)),
            'invoice_enabled'    => $this->pickBool('invoice_enabled', $company, (bool) ($defaults['invoice_enabled'] ?? true)),
            'payment_enabled'    => $this->pickBool('payment_enabled', $company, (bool) ($defaults['payment_enabled'] ?? true)),
            'wallet_enabled'     => $this->pickBool('wallet_enabled', $company, (bool) ($defaults['wallet_enabled'] ?? true)),
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

        return $resolved;
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
            return ['value' => $value, 'source' => 'company'];
        }

        return ['value' => $default, 'source' => 'default'];
    }

    private function toBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
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
        if (isset($company[$field]) && $company[$field] !== '') {
            return ['value' => max($minimum, (int) $company[$field]), 'source' => 'company'];
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
        if (is_string($value) && in_array($value, $allowed, true)) {
            return ['value' => $this->conflictChoice($field, $value), 'source' => 'company'];
        }

        return ['value' => $this->conflictChoice($field, $default), 'source' => 'default'];
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
        if (!array_key_exists($field, $company)) {
            return ['value' => 'both', 'source' => 'default'];
        }

        $value = $company[$field];
        if ($value === 'off' || $value === null || $value === '') {
            return ['value' => 'both', 'source' => 'company'];
        }
        if (is_string($value) && in_array($value, ['both', 'outbound', 'inbound'], true)) {
            return ['value' => $value, 'source' => 'company'];
        }

        return ['value' => 'both', 'source' => 'default'];
    }

    /**
     * Connection shows a stored report primary as Fleetbase. Sync uses that same choice.
     */
    private function conflictChoice(string $field, string $value): string
    {
        if (str_ends_with($field, '_conflict') && $value === 'report') {
            return 'fleetbase';
        }

        return $value;
    }
}
