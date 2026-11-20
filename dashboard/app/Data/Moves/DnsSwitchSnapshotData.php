<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * `control_plane_moves.dns_switch` (docs/14 §13.4): the DNS records to
 * change after the hand-over, taken when it starts. The old dashboard shows
 * them after it handed over, when its copy of the new server no longer
 * reports; the new dashboard has them from the restored database.
 */
final class DnsSwitchSnapshotData extends Data
{
    /**
     * @param  list<string>  $addresses  the new server's public addresses
     * @param  list<string>  $oldAddresses  the old server's
     * @param  list<DnsSwitchEntryData>  $records
     */
    public function __construct(
        public string $takenAt,
        public array $addresses,
        public array $oldAddresses,
        public array $records,
    ) {}
}
