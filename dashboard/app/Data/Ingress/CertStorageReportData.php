<?php

declare(strict_types=1);

namespace App\Data\Ingress;

use Spatie\LaravelData\Data;

/** The edge's certificate storage as it last reported it (Report.ingress.storage). */
final class CertStorageReportData extends Data
{
    public function __construct(
        /** `local`, `shared` or `degraded`. */
        public string $state,
        public int $mirrored,
        public ?string $message = null,
    ) {}
}
