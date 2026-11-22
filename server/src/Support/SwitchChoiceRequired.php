<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * Intuit returned a different QuickBooks company than the organization's linked records belong to. Nothing was
 * exchanged or changed: the user must first say what happens to those records. $handle is what to answer with.
 */
class SwitchChoiceRequired extends \RuntimeException
{
    public function __construct(public readonly string $handle = '')
    {
        parent::__construct('The organization has linked records that need a choice first.');
    }
}
