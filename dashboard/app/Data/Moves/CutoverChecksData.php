<?php

declare(strict_types=1);

namespace App\Data\Moves;

use Spatie\LaravelData\Data;

/**
 * The checks after the control plane changed machines and whether each one
 * passed (`App\Queries\ControlPlaneCutoverChecks`), shared by the move checklist and
 * the recovery checklist.
 */
final class CutoverChecksData extends Data
{
    /**
     * @param  list<string>  $expectedAddresses  the edge addresses the names must reach
     * @param  list<CutoverDnsRowData>  $dns
     * @param  list<CutoverServerData>  $servers
     */
    public function __construct(
        public array $expectedAddresses,
        public array $dns,
        public array $servers,
        /** Null until the registry was checked. */
        public ?CutoverImagesData $images,
        public bool $dnsOk,
        public bool $imagesOk,
        public bool $serversOk,
    ) {}

    /**
     * @return list<string>
     */
    public function openSteps(): array
    {
        return array_keys(array_filter(['dns' => ! $this->dnsOk, 'images' => ! $this->imagesOk, 'servers' => ! $this->serversOk]));
    }
}
