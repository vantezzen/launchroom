<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Data;

/**
 * One component of a service's live image (docs/22 §3.4, §11).
 */
final class InventoryComponentResource extends Data
{
    public function __construct(
        public string $ecosystem,
        public string $name,
        public string $version,
        public ?string $purl,
        /** `os`, `library` or `runtime`. */
        public string $kind,
        /** `runtime`, `dev`, `client` or `unknown`. */
        public string $scope,
        /** `installed` or `bundled`. */
        public string $presence,
        public bool $direct,
    ) {}
}
