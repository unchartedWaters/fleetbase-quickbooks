<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Models\Link;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * FleetbaseDirectory's lookups for QuickBooks deletes and merges: the links a delete matches,
 * and the local record a merged QuickBooks record now belongs to. Split from
 * AppliesRemoteDeletes to keep each file readable.
 *
 * @phpstan-import-type RemoteDelete from FleetbaseDirectory
 */
trait FindsDeletedLinks
{
    /**
     * Points the links of a merged-away QuickBooks record at the record it went into.
     * True when a link moved, false when this company already links the surviving record
     * (both links are kept), null when nothing links the merged-away record.
     */
    public function followMergedRecord(
        string $companyUuid,
        string $realmId,
        string $localType,
        string $fromId,
        string $intoId,
    ): ?bool {
        if ($companyUuid === '' || $localType === '' || $fromId === '' || $intoId === '' || $fromId === $intoId) {
            return null;
        }
        if ($this->memory !== null) {
            return $this->followMemoryMerge($companyUuid, $realmId, $localType, $fromId, $intoId);
        }

        try {
            $scope = fn (string $remoteId) => Link::query()
                ->where('company_uuid', $companyUuid)
                ->where('realm_id', $realmId)
                ->where('local_type', $localType)
                ->where('qbo_id', $remoteId);
            if ($scope($fromId)->exists() === false) {
                return null;
            }
            if ($scope($intoId)->exists() === true) {
                return false;
            }
            // No SyncToken: the next sync lets QuickBooks, as Primary, win on the surviving record.
            $scope($fromId)->update(['qbo_id' => $intoId, 'sync_token' => '', 'updated_at' => Carbon::now()]);

            return true;
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return null;
            }

            throw $exception;
        }
    }

    private function followMemoryMerge(
        string $companyUuid,
        string $realmId,
        string $localType,
        string $fromId,
        string $intoId,
    ): ?bool {
        $memory = $this->memory;
        if ($memory === null) {
            return null;
        }
        $from = null;
        foreach ($memory->links as $index => $link) {
            $other = ($link['company_uuid'] ?? '') !== $companyUuid
                || ($link['realm_id'] ?? '') !== $realmId
                || ($link['local_type'] ?? '') !== $localType;
            if ($other === true) {
                continue;
            }
            if ((string) ($link['qbo_id'] ?? '') === $intoId) {
                return false;
            }
            if ((string) ($link['qbo_id'] ?? '') === $fromId) {
                $from = $index;
            }
        }
        if ($from === null) {
            return null;
        }
        $memory->links[$from]['qbo_id']     = $intoId;
        $memory->links[$from]['sync_token'] = '';
        $memory->rebuildIndex();

        return true;
    }

    /**
     * A delete that echoes a void, delete or close Fleetbase already made needs no note.
     *
     * @param array<int, array{local_type: string, local_uuid: string, error: string, outcome?: string}> $notes
     *
     * @return array<int, array{local_type: string, local_uuid: string, error: string, outcome?: string}>
     */
    private function withoutRetiredLocally(string $companyUuid, array $notes): array
    {
        if ($this->memory !== null || $notes === []) {
            return $notes;
        }
        $classes = [
            'invoice'  => 'Fleetbase\\Ledger\\Models\\Invoice',
            'customer' => 'Fleetbase\\FleetOps\\Models\\Customer',
            'wallet'   => 'Fleetbase\\Ledger\\Models\\Wallet',
        ];
        $byType = [];
        foreach ($notes as $note) {
            if (isset($classes[$note['local_type']]) === true) {
                $byType[$note['local_type']][] = $note['local_uuid'];
            }
        }
        $retired = [];
        foreach ($byType as $type => $uuids) {
            foreach ($this->retiredUuids($classes[$type], $companyUuid, $type, $uuids) as $uuid) {
                $retired[$type . '|' . $uuid] = true;
            }
        }

        return array_values(array_filter(
            $notes,
            static fn (array $note): bool => isset($retired[$note['local_type'] . '|' . $note['local_uuid']]) === false
        ));
    }

    /**
     * @param class-string<Model> $class
     * @param array<int, string>  $uuids
     *
     * @return array<int, string>
     */
    private function retiredUuids(string $class, string $companyUuid, string $type, array $uuids): array
    {
        if (class_exists($class) === false) {
            return [];
        }

        try {
            $model = new $class();
            if ($model instanceof Model === false) {
                return [];
            }
            $query = $model->getConnection()->table($model->getTable())
                ->where('company_uuid', $companyUuid)
                ->whereIn('uuid', $uuids)
                ->where(function (QueryBuilder $retired) use ($type): void {
                    $retired->whereNotNull('deleted_at');
                    if ($type === 'invoice') {
                        $retired->orWhereRaw("lower(status) in ('void', 'voided', 'cancelled', 'canceled')");
                    }
                    if ($type === 'wallet') {
                        $retired->orWhere('status', 'closed');
                    }
                });

            return array_map(static fn (mixed $uuid): string => (string) $uuid, $query->pluck('uuid')->all());
        } catch (\Throwable $exception) {
            if ($this->isUnavailableStorage($exception) === true) {
                return [];
            }

            throw $exception;
        }
    }

    /**
     * @return array<int, string>
     */
    private function linkedLocalUuids(
        string $companyUuid,
        string $realmId,
        string $localType,
        string $quickbooksId,
        ?string $localUuid,
    ): array {
        if ($quickbooksId === '' && ($localUuid === null || $localUuid === '')) {
            return [];
        }

        try {
            $query = Link::query()->where('company_uuid', $companyUuid)->where('local_type', $localType);
            if ($realmId !== '') {
                $query->where('realm_id', $realmId);
            }
            $query->where(function ($inner) use ($quickbooksId, $localUuid): void {
                $started = false;
                if ($quickbooksId !== '') {
                    $inner->where('qbo_id', $quickbooksId);
                    $started = true;
                }
                if (is_string($localUuid) === true && $localUuid !== '') {
                    $method = $started === true ? 'orWhere' : 'where';
                    $inner->{$method}('local_uuid', $localUuid);
                }
            });
            $ids = [];
            foreach ($query->pluck('local_uuid') as $uuid) {
                $linkedUuid = (string) $uuid;
                if ($linkedUuid !== '') {
                    $ids[] = $linkedUuid;
                }
            }

            return array_values(array_unique($ids));
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return [];
            }

            throw $exception;
        }
    }

    /**
     * @param array<int, RemoteDelete> $deletions
     */
    private function deletionMatchesLink(
        array $deletions,
        string $type,
        string $realm,
        string $qbo,
        string $local,
    ): bool {
        foreach ($deletions as $deletion) {
            if (is_array($deletion) === false || (string) ($deletion['local_type'] ?? '') !== $type) {
                continue;
            }
            $deletionRealm = (string) ($deletion['realm_id'] ?? '');
            if ($deletionRealm !== '' && $realm !== $deletionRealm) {
                continue;
            }
            $deletionQbo   = (string) ($deletion['qbo_id'] ?? '');
            $deletionLocal = (string) ($deletion['local_uuid'] ?? '');
            if ($deletionQbo !== '' && $qbo === $deletionQbo) {
                return true;
            }
            if ($deletionLocal !== '' && $local === $deletionLocal) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, RemoteDelete> $deletions
     *
     * @return array<int, array<string, mixed>>
     */
    private function linksForDeletions(string $companyUuid, array $deletions): array
    {
        [$clauses, $paymentIds] = $this->deletionLookup($deletions);
        if ($clauses === [] && $paymentIds === []) {
            return [];
        }

        try {
            $links = $this->queryDeletionLinks($companyUuid, $clauses, $paymentIds);
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isMissingTable($exception) === true) {
                return [];
            }

            throw $exception;
        }

        return $this->deletionLinkRows($links);
    }

    /**
     * @param array<int, array<string, mixed>> $deletions
     *
     * @return array{
     *     0: array<int, array{type: string, realm: string, qbo: string, local: string}>,
     *     1: array<int, string>
     * }
     */
    private function deletionLookup(array $deletions): array
    {
        $paymentIds = [];
        $clauses    = [];
        foreach ($deletions as $deletion) {
            if (is_array($deletion) === false) {
                continue;
            }
            $type  = (string) ($deletion['local_type'] ?? '');
            $realm = (string) ($deletion['realm_id'] ?? '');
            $qbo   = (string) ($deletion['qbo_id'] ?? '');
            $local = (string) ($deletion['local_uuid'] ?? '');
            if ($type === '' || ($qbo === '' && $local === '')) {
                continue;
            }
            if ($type === 'payment' && $qbo !== '') {
                $paymentIds[] = $qbo;
            }
            $clauses[] = ['type' => $type, 'realm' => $realm, 'qbo' => $qbo, 'local' => $local];
        }

        return [$clauses, array_values(array_unique($paymentIds))];
    }

    /**
     * @param array<int, array{type: string, realm: string, qbo: string, local: string}> $clauses
     * @param array<int, string>                                                         $paymentIds
     *
     * @return iterable<int, Link>
     */
    private function queryDeletionLinks(string $companyUuid, array $clauses, array $paymentIds): iterable
    {
        return Link::query()
            ->where('company_uuid', $companyUuid)
            ->where(function ($scope) use ($clauses, $paymentIds): void {
                $this->constrainDeletionScope($scope, $clauses, $paymentIds);
            })
            ->get(['uuid', 'realm_id', 'local_type', 'local_uuid', 'qbo_id']);
    }

    /**
     * @param Builder<Link>                                                              $scope
     * @param array<int, array{type: string, realm: string, qbo: string, local: string}> $clauses
     * @param array<int, string>                                                         $paymentIds
     */
    private function constrainDeletionScope(
        Builder $scope,
        array $clauses,
        array $paymentIds,
    ): void {
        $started = false;
        foreach ($clauses as $clause) {
            $method = $started === true ? 'orWhere' : 'where';
            $scope->{$method}(function (Builder $inner) use ($clause): void {
                $this->constrainDeletionClause($inner, $clause);
            });
            $started = true;
        }
        if ($paymentIds !== []) {
            $method = $started === true ? 'orWhere' : 'where';
            $scope->{$method}(function (Builder $inner) use ($paymentIds): void {
                $inner->where('local_type', 'payment-invoice')->whereIn('local_uuid', $paymentIds);
            });
        }
    }

    /**
     * @param Builder<Link>                                                  $inner
     * @param array{type: string, realm: string, qbo: string, local: string} $clause
     */
    private function constrainDeletionClause(Builder $inner, array $clause): void
    {
        $inner->where('local_type', $clause['type']);
        if ($clause['realm'] !== '') {
            $inner->where('realm_id', $clause['realm']);
        }
        $inner->where(function ($match) use ($clause): void {
            $this->constrainDeletionMatch($match, $clause);
        });
    }

    /**
     * @param Builder<Link>                                                  $match
     * @param array{type: string, realm: string, qbo: string, local: string} $clause
     */
    private function constrainDeletionMatch(Builder $match, array $clause): void
    {
        $open = false;
        if ($clause['qbo'] !== '') {
            $match->where('qbo_id', $clause['qbo']);
            $open = true;
        }
        if ($clause['local'] !== '') {
            $way = $open === true ? 'orWhere' : 'where';
            $match->{$way}('local_uuid', $clause['local']);
        }
    }

    /**
     * @param iterable<int, Link> $links
     *
     * @return array<int, array<string, mixed>>
     */
    private function deletionLinkRows(iterable $links): array
    {
        $rows = [];
        foreach ($links as $link) {
            if (is_object($link) === true) {
                $rows[] = [
                    'uuid'       => (string) $link->uuid,
                    'realm_id'   => (string) $link->realm_id,
                    'local_type' => (string) $link->local_type,
                    'local_uuid' => (string) $link->local_uuid,
                    'qbo_id'     => (string) $link->qbo_id,
                ];
            }
        }

        return $rows;
    }
}
