<?php

namespace Fleetbase\Quickbooks\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Payment days as the organization sees them. A Ledger timestamp is stored in the app timezone
 * (usually UTC), so an evening payment in the Americas would otherwise fall on the next day.
 */
class LedgerPaymentDates
{
    /**
     * @var array<string, string> timezone by company uuid
     */
    private array $zones = [];

    /**
     * The day of a stored payment time in the company's timezone. Midnight in the app timezone
     * is a date without a time (a QuickBooks payment date saved by this package), so it keeps
     * its day; shifting it would move the date QuickBooks already has.
     */
    public function day(ConnectionInterface $database, string $companyUuid, mixed $value): string
    {
        $time = $this->appTime($value);
        if ($time === null) {
            return is_string($value) === true ? substr(trim($value), 0, 10) : '';
        }
        if ($time->format('H:i:s') === '00:00:00') {
            return $time->toDateString();
        }

        return $time->setTimezone($this->zone($database, $companyUuid))->toDateString();
    }

    /**
     * The day of the last payment Ledger recorded for each invoice, by invoice uuid. Ledger keeps
     * no paid date on a part-paid invoice, but each payment is an invoice_payment transaction.
     *
     * @param array<int, string> $invoiceUuids
     *
     * @return array<string, string>
     */
    public function lastPaymentDays(ConnectionInterface $database, string $companyUuid, array $invoiceUuids): array
    {
        if ($invoiceUuids === []) {
            return [];
        }

        try {
            $rows = $database->table('transactions')
                ->where('company_uuid', $companyUuid)
                ->where('type', 'invoice_payment')
                ->whereIn('subject_uuid', $invoiceUuids)
                ->whereNull('deleted_at')
                ->groupBy('subject_uuid')
                ->selectRaw('subject_uuid, max(created_at) as paid')
                ->get();
        } catch (\Throwable $exception) {
            SafeLog::warning('QuickBooks could not read Ledger payment dates.', [
                'company_uuid' => $companyUuid,
                'exception'    => $exception::class,
            ]);

            return [];
        }

        $days = [];
        foreach ($rows as $row) {
            /** @var \stdClass $row */
            $paid = $this->appTime($row->paid ?? null);
            if ($paid !== null) {
                $days[(string) $row->subject_uuid] = $paid
                    ->setTimezone($this->zone($database, $companyUuid))
                    ->toDateString();
            }
        }

        return $days;
    }

    /**
     * The reference and method of the last payment Ledger recorded for each invoice, by invoice
     * uuid. A payment without either is left out.
     *
     * @param array<int, string> $invoiceUuids
     *
     * @return array<string, array{reference: string, method: string}>
     */
    public function lastPaymentDetails(ConnectionInterface $database, string $companyUuid, array $invoiceUuids): array
    {
        if ($invoiceUuids === []) {
            return [];
        }

        try {
            $rows = [];
            foreach (array_chunk(array_values(array_unique($invoiceUuids)), 500) as $chunk) {
                array_push($rows, ...$this->lastPayments($database, $companyUuid, $chunk));
            }
        } catch (\Throwable $exception) {
            SafeLog::warning('QuickBooks could not read Ledger payment references.', [
                'company_uuid' => $companyUuid,
                'exception'    => $exception::class,
            ]);

            return [];
        }

        $details = [];
        foreach ($rows as $row) {
            /* @var \stdClass $row */
            $details[(string) $row->subject_uuid] = [
                'reference' => trim((string) ($row->reference ?? '')),
                'method'    => trim((string) ($row->payment_method ?? '')),
            ];
        }

        return array_filter(
            $details,
            static fn (array $detail): bool => $detail['reference'] !== '' || $detail['method'] !== ''
        );
    }

    /**
     * Only each invoice's payments at its latest time, oldest id first, so the last row per invoice wins.
     *
     * @param array<int, string> $invoiceUuids
     *
     * @return array<int, \stdClass>
     */
    private function lastPayments(ConnectionInterface $database, string $companyUuid, array $invoiceUuids): array
    {
        $payments = static fn (ConnectionInterface $database, string $table) => $database->table($table)
            ->where('company_uuid', $companyUuid)
            ->where('type', 'invoice_payment')
            ->whereIn('subject_uuid', $invoiceUuids)
            ->whereNull('deleted_at');
        $latest = $payments($database, 'transactions')
            ->groupBy('subject_uuid')
            ->selectRaw('subject_uuid as latest_uuid, max(created_at) as latest_at');

        $found = $payments($database, 'transactions')
            ->joinSub($latest, 'latest', static function ($join): void {
                $join->on('transactions.subject_uuid', '=', 'latest.latest_uuid')
                    ->on('transactions.created_at', '=', 'latest.latest_at');
            })
            ->orderBy('transactions.id')
            ->get(['transactions.subject_uuid', 'transactions.reference', 'transactions.payment_method']);
        $rows = [];
        foreach ($found as $row) {
            if ($row instanceof \stdClass) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The company's timezone, or the app timezone when it has none or an unknown one.
     */
    private function zone(ConnectionInterface $database, string $companyUuid): string
    {
        if (isset($this->zones[$companyUuid]) === true) {
            return $this->zones[$companyUuid];
        }

        $zone = '';
        try {
            $zone = trim((string) $database->table('companies')->where('uuid', $companyUuid)->value('timezone'));
        } catch (\Throwable) {
            $zone = '';
        }
        if ($zone === '' || in_array($zone, \DateTimeZone::listIdentifiers(), true) === false) {
            $zone = self::appZone();
        }

        return $this->zones[$companyUuid] = $zone;
    }

    private function appTime(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->setTimezone(self::appZone());
        }
        if (is_string($value) === false || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, self::appZone());
        } catch (\Throwable) {
            return null;
        }
    }

    private static function appZone(): string
    {
        $zone = config('app.timezone');

        return is_string($zone) === true && $zone !== '' ? $zone : date_default_timezone_get();
    }
}
