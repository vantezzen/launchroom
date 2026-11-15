<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\HoldKind;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A hold of $kind on these service instances started or ended (docs/14
 * §9.4). Dispatched after the surrounding transaction commits.
 */
final readonly class HoldChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $instanceIds
     */
    public function __construct(
        public HoldKind $kind,
        public array $instanceIds,
    ) {}
}
