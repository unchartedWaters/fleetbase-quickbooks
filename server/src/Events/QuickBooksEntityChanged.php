<?php

namespace Fleetbase\Quickbooks\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One QuickBooks entity changed. Other Fleetbase packages listen for this class.
 * The payload is scalars, so listeners do not need QuickBooks models.
 */
class QuickBooksEntityChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public string $companyUuid,
        public string $realmId,
        public string $entityType,
        public string $quickbooksId,
        public string $operation,
        public ?string $localUuid,
    ) {
    }
}
