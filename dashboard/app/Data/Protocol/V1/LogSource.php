<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Enums\LogSourceKind;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

final class LogSource extends Data
{
    public function __construct(
        public LogSourceKind $kind,
        #[Regex('/^(?:tsk|dpl|wl)_[0-9A-HJKMNP-TV-Z]{26}$/D')]
        public string $id,
    ) {}
}
