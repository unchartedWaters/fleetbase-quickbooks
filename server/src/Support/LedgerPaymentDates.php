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
    public function day(ConnectionInterface $db, string $companyUuid, mixed $value): string
    {
        $time = $this->appTime($value);
        if ($time === null) {
            return is_string($value) === true ? substr(trim($value), 0, 10) : '';
        }
        if ($time->format('H:i:s') === '00:00:00') {
            return $time->toDateString();
        }

        return $time->setTimezone($this->zone($db, $companyUuid))->toDateString();
    }

    /**
     * The day of the last payment Ledger recorded for each invoice, by invoice uuid. Ledger keeps
     * no paid date on a part-paid invoice, but each payment is an invoice_payment transaction.
     *
     * @param array<int, string> $invoiceUuids
     *
     * @return array<string, string>
     */
    public function lastPaymentDays(ConnectionInterface $db, string $companyUuid, array $invoiceUuids): array
    {
        if ($invoiceUuids === []) {
            return [];
        }

        try {
            $rows = $db->table('transactions')
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
                $days[(string) $row->subject_uuid] = $paid->setTimezone($this->zone($db, $companyUuid))->toDateString();
            }
        }

        return $days;
    }

    /**
     * The company's timezone, or the app timezone when it has none or an unknown one.
     */
    private function zone(ConnectionInterface $db, string $companyUuid): string
    {
        if (isset($this->zones[$companyUuid]) === true) {
            return $this->zones[$companyUuid];
        }

        $zone = '';
        try {
            $zone = trim((string) $db->table('companies')->where('uuid', $companyUuid)->value('timezone'));
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
