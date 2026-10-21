<?php

declare(strict_types=1);

namespace App\Data\Specs;

use App\Enums\DomainKind;
use Spatie\LaravelData\Data;

final class DomainSpec extends Data
{
    public function __construct(
        public string $id,
        public string $hostname,
        public DomainKind $kind,
        public ?string $redirectTo = null,
    ) {}
}
