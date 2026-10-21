<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Report `ingress.storage` (report.json CertStorageStatus, docs/05 §5).
 */
final class CertStorageStatus extends Data
{
    public function __construct(
        #[In(['local', 'shared', 'degraded'])]
        public string $state,
        #[Min(0)]
        public int $mirrored,
        #[Max(1024)]
        public string|Optional $message = new Optional,
    ) {}
}
