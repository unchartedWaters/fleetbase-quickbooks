<?php

namespace Fleetbase\Quickbooks\Support;

use Fleetbase\Quickbooks\Models\Link;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Activity shows a few errors per run, as sentences, each naming the record it is about.
 */
class ActivityErrors
{
    public const SHOWN = 3;

    /** Errors read per run before names are added and duplicates removed. */
    public const CANDIDATES = 10;

    /** Notes about a run's settings rather than the record they are stored on. */
    private const GENERAL_NOTES = ['quickbooks.messages.fleetbase-primary-replaced'];

    /** @var array<string, array{0: string, 1: string}> */
    private const RECORDS = [
        'customer' => ['Fleetbase\\FleetOps\\Models\\Customer', 'name'],
        'invoice'  => ['Fleetbase\\Ledger\\Models\\Invoice', 'number'],
        'wallet'   => ['Fleetbase\\Ledger\\Models\\Wallet', 'name'],
        'product'  => ['Fleetbase\\Storefront\\Models\\Product', 'name'],
    ];

    /**
     * @param array<int, string> $messages
     */
    public static function join(array $messages): ?string
    {
        $sentences = array_values(array_unique(array_filter(
            array_map([self::class, 'sentence'], $messages),
            static fn (string $text): bool => $text !== ''
        )));

        return $sentences === [] ? null : implode(' ', array_slice($sentences, 0, self::SHOWN));
    }

    /**
     * Whether a stored error is a general note. Notes take no parameters, so each is stored as its bare key.
     */
    public static function isGeneralNote(string $error): bool
    {
        return in_array(trim($error), self::GENERAL_NOTES, true);
    }

    public static function sentence(string $message): string
    {
        $message = rtrim(trim($message), " \t;:,");
        if ($message === '') {
            return '';
        }

        return preg_match('/[.!?]["\')]?$/u', $message) === 1 ? $message : $message . '.';
    }

    /**
     * The names of these records, read only from this company's rows. Deleted records keep
     * their name, since an error can be about a record removed since.
     *
     * @param array<string, array<int, string>> $uuidsByType
     *
     * @return array<string, string> name by "type|uuid"
     */
    public static function names(string $companyUuid, array $uuidsByType): array
    {
        $names = [];
        foreach ($uuidsByType as $type => $uuids) {
            $record = self::RECORDS[$type] ?? null;
            $uuids  = array_values(array_unique(array_filter($uuids)));
            if ($type === 'item' && $uuids !== []) {
                $names += self::productNames($companyUuid, $uuids);
                continue;
            }
            $class = $record[0] ?? '';
            if ($record === null || $uuids === [] || class_exists($class) === false) {
                continue;
            }
            try {
                $rows = self::rows($class, $record[1], $companyUuid, $uuids);
            } catch (\Throwable) {
                // A name is a convenience: Activity still shows the error without it.
                continue;
            }
            $field = $record[1];
            foreach ($rows as $row) {
                $name = trim((string) ($row->{$field} ?? ''));
                if ($name !== '') {
                    $names[$type . '|' . (string) ($row->uuid ?? '')] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * The product name a QuickBooks product link stands for, as its link keeps it.
     *
     * @param array<int, string> $uuids
     *
     * @return array<string, string> name by "item|uuid"
     */
    private static function productNames(string $companyUuid, array $uuids): array
    {
        $names = [];
        try {
            $links = Link::query()->where('company_uuid', $companyUuid)->where('local_type', 'item')->toBase()
                ->whereIn('local_uuid', $uuids)->get(['local_uuid', 'name']);
        } catch (\Throwable) {
            return [];
        }
        foreach ($links as $link) {
            $link = (array) $link;
            $name = trim((string) ($link['name'] ?? ''));
            if ($name !== '') {
                $names['item|' . (string) ($link['local_uuid'] ?? '')] = $name;
            }
        }

        return $names;
    }

    /**
     * @param class-string       $class
     * @param array<int, string> $uuids
     *
     * @return iterable<int, object> plain rows: no relations or attributes are loaded
     */
    private static function rows(string $class, string $field, string $companyUuid, array $uuids): iterable
    {
        $query = $class::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($class), true) === true) {
            $query->withTrashed();
        }

        return $query->where('company_uuid', $companyUuid)->whereIn('uuid', $uuids)->toBase()->get(['uuid', $field]);
    }

    /**
     * "Invoice INV-7: ..." unless the message names the record already.
     */
    public static function labeled(string $type, ?string $name, string $message, ?string $locale = null): string
    {
        $named = $name !== null && $name !== '' && (isset(self::RECORDS[$type]) === true || $type === 'item');
        if ($named === false || str_contains($message, (string) $name) === true) {
            return $message;
        }

        return Translation::get('quickbooks.activity.record-error', [
            'record'  => Translation::get('quickbooks.activity.record.' . $type, ['name' => $name], $locale),
            'message' => self::sentence($message),
        ], $locale);
    }
}
