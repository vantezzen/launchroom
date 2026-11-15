<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Data\Attributes\PublicIdOf;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use Spatie\LaravelData\Data;

/**
 * A canvas edge drawn from `from` (the consumer) to `to` (the provider).
 */
final class CreateConnectionData extends Data
{
    public function __construct(
        #[PublicIdOf('svc')]
        public string $from,
        #[PublicIdOf('svc')]
        public string $to,
        public ConnectionMode $mode = ConnectionMode::Private,
        public ConnectionStyle $style = ConnectionStyle::Url,
    ) {}
}
