<?php

namespace Illuminate\Foundation\Events;

/**
 * This package depends on the split illuminate/* packages. Illuminate\Foundation ships only in
 * laravel/framework, which the host Fleetbase application always provides at runtime, so the trait
 * exists when the event actually runs. Declared here purely so static analysis can resolve it.
 */
trait Dispatchable
{
}
