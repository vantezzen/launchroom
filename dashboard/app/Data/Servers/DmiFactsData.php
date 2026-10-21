<?php

declare(strict_types=1);

namespace App\Data\Servers;

use Spatie\LaravelData\Data;

/**
 * SMBIOS fields of a server (`/sys/class/dmi/id`, world-readable ones only).
 */
final class DmiFactsData extends Data
{
    public function __construct(
        public ?string $sysVendor = null,
        public ?string $productName = null,
        public ?string $productFamily = null,
        public ?string $boardVendor = null,
        public ?string $boardName = null,
        public ?string $biosVendor = null,
        /** SMBIOS chassis type (3 desktop, 10 notebook, 17/23 server, 35 mini PC, …). */
        public ?int $chassisType = null,
    ) {}
}
