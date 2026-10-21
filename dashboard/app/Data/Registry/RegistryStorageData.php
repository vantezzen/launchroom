<?php

declare(strict_types=1);

namespace App\Data\Registry;

use App\Models\Registry\RegistryStorage;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * The registry's S3 storage as shown in Settings → Registry. Whitelisted
 * fields only: never the credentials (docs/09 R12).
 */
final class RegistryStorageData extends Data
{
    public function __construct(
        public string $endpoint,
        public string $bucket,
        public ?string $region,
        public string $prefix,
        public bool $forcePathStyle,
        public bool $allowPrivateNetwork,
        public ?CarbonInterface $updatedAt,
    ) {}

    public static function fromStorage(RegistryStorage $storage): self
    {
        return new self(
            $storage->endpoint,
            $storage->bucket,
            $storage->region,
            $storage->prefix,
            $storage->force_path_style,
            $storage->allow_private_network,
            $storage->updated_at,
        );
    }
}
