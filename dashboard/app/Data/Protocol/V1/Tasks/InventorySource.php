<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Data\Protocol\V1\Sealed;
use Spatie\LaravelData\Data;

/**
 * The repository an image was built from (protocol InventorySource): its
 * lockfiles name the packages the image bundles. The credential is sealed
 * at lease time.
 */
final class InventorySource extends Data
{
    public function __construct(
        public RepoSpec $repo,
        public ?string $rootDir = null,
        public ?Sealed $cloneToken = null,
        public ?Sealed $sshKey = null,
    ) {}
}
