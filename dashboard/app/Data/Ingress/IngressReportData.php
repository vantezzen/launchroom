<?php

declare(strict_types=1);

namespace App\Data\Ingress;

use App\Data\Protocol\V1\CertStorageStatus;
use App\Data\Protocol\V1\IngressStatus;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * `servers.ingress_status`: the summary of the edge's latest Report.ingress
 * (App\Actions\Servers\RecordCertificates).
 */
final class IngressReportData extends Data
{
    public function __construct(
        /** `pending`, `applied` or `failed`. */
        public string $state,
        public int $routes,
        public ?CertStorageReportData $storage = null,
    ) {}

    public static function fromProtocol(IngressStatus $ingress): self
    {
        $storage = $ingress->storage instanceof CertStorageStatus ? $ingress->storage : null;

        return new self($ingress->state, $ingress->routes, $storage instanceof CertStorageStatus ? new CertStorageReportData(
            $storage->state,
            $storage->mirrored,
            $storage->message instanceof Optional ? null : mb_substr($storage->message, 0, 1024),
        ) : null);
    }
}
