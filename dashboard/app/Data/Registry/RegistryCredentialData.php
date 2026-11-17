<?php

declare(strict_types=1);

namespace App\Data\Registry;

use App\Enums\Registry\RegistryCredentialStatus;
use App\Models\Registry\RegistryCredential;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A private registry as shown in Settings → Private registries. Whitelisted
 * fields only: never the password or token (docs/09 R12).
 */
final class RegistryCredentialData extends Data
{
    public function __construct(
        public string $id,
        public string $host,
        public string $username,
        public RegistryCredentialStatus $status,
        public ?CarbonInterface $lastCheckedAt,
        public ?string $lastError,
        public ?CarbonInterface $createdAt,
    ) {}

    public static function fromCredential(RegistryCredential $credential): self
    {
        return new self(
            $credential->public_id,
            $credential->host,
            $credential->username,
            $credential->status,
            $credential->last_checked_at,
            $credential->last_error,
            $credential->created_at,
        );
    }
}
