<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Attributes\PublicIdOf;
use App\Enums\LogSourceKind;
use Spatie\LaravelData\Data;

final class LogSource extends Data
{
    public function __construct(
        public LogSourceKind $kind,
        #[PublicIdOf('tsk', 'dpl', 'wl')]
        public string $id,
    ) {}
}
