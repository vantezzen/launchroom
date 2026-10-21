<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * A canvas edge drawn from `from` (the consumer) to `to` (the provider).
 */
final class CreateConnectionData extends Data
{
    public function __construct(
        #[Regex('/^svc_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
        public string $from,
        #[Regex('/^svc_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D')]
        public string $to,
        public ConnectionMode $mode = ConnectionMode::Private,
        public ConnectionStyle $style = ConnectionStyle::Url,
    ) {}
}
