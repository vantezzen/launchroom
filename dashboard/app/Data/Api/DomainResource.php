<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Enums\CertStatus;
use App\Enums\DomainKind;
use Spatie\LaravelData\Data;

final class DomainResource extends Data
{
    public function __construct(
        public string $id,
        public string $hostname,
        public DomainKind $kind,
        public ?string $redirectTo,
        public ?CertStatus $certStatus,
        public ?string $certExpiresAt,
        public bool $hsts,
    ) {}
}
