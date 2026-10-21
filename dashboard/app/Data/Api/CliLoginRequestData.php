<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\CliLoginStatus;
use Spatie\LaravelData\Data;

/**
 * A CLI sign-in as the approval page shows it. Client name, hostname and
 * version are what the CLI claimed (display only).
 */
final class CliLoginRequestData extends Data
{
    public function __construct(
        public string $userCode,
        public CliLoginStatus $status,
        public bool $expired,
        public string $clientName,
        public ?string $hostname,
        public ?string $version,
        public ?string $ip,
        public string $createdAt,
        public string $expiresAt,
    ) {}
}
