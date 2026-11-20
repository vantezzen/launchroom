<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * After a control-plane move (docs/14 §13.4): which DNS records to point at
 * the new server, how to reach the new dashboard before they do, and live
 * checks of each. Shown on the old dashboard after the hand-over and in
 * the new dashboard's checklist.
 */
final class DnsSwitchData extends Data
{
    /**
     * @param  list<DnsSwitchValueData>  $addresses  the new server's public addresses
     * @param  list<string>  $oldAddresses
     * @param  list<DnsSwitchRecordData>  $records
     */
    public function __construct(
        public ?string $newUrl,
        public array $addresses,
        public array $oldAddresses,
        public array $records,
        /** Null when the new address works without DNS (an IP or sslip.io address). */
        public ?DnsSwitchTunnelData $reachBeforeDns,
        public bool $checked,
        public int $pointed,
        public int $total,
    ) {}
}
