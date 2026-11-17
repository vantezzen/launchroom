<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Domains\DnsCheckData;
use App\Enums\CertFailureKind;
use App\Enums\CertStatus;
use App\Enums\DomainKind;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A hostname routed to a service instance (docs/05 §5).
 *
 * @property int $id
 * @property string $public_id
 * @property int $service_instance_id
 * @property string $hostname
 * @property DomainKind $kind
 * @property string|null $redirect_to
 * @property DnsCheckData|null $verification the latest live DNS check (App\Actions\Domains\VerifyDomain)
 * @property CertStatus|null $cert_status reported by edges (docs/05 §5)
 * @property Carbon|null $cert_expires_at
 * @property Carbon|null $cert_not_before
 * @property string|null $cert_issuer
 * @property bool $cert_wildcard served by a DNS-01 wildcard certificate
 * @property string|null $cert_error last issuance/renewal error (Caddy's message)
 * @property Carbon|null $cert_error_at
 * @property CertFailureKind|null $cert_failure_kind why the last attempt failed (Report.ingress.certificates[].failureKind)
 * @property Carbon|null $cert_retry_after the certificate authority's next allowed attempt (rate limit)
 * @property Carbon|null $cert_checked_at
 * @property int|null $cert_server_id the edge that reported it
 * @property bool $hsts Strict-Transport-Security once the certificate is issued (custom domains)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ServiceInstance $serviceInstance
 */
#[Fillable(['team_id', 'service_instance_id', 'hostname', 'kind', 'redirect_to'])]
final class Domain extends Model
{
    use BelongsToTeam, HasPublicId;

    public static function publicIdPrefix(): string
    {
        return 'dom';
    }

    /**
     * @return BelongsTo<ServiceInstance, $this>
     */
    public function serviceInstance(): BelongsTo
    {
        return $this->belongsTo(ServiceInstance::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DomainKind::class,
            'verification' => DnsCheckData::class,
            'cert_expires_at' => 'datetime',
            'cert_status' => CertStatus::class,
            'cert_not_before' => 'datetime',
            'cert_wildcard' => 'boolean',
            'cert_error_at' => 'datetime',
            'cert_failure_kind' => CertFailureKind::class,
            'cert_retry_after' => 'datetime',
            'cert_checked_at' => 'datetime',
            'hsts' => 'boolean',
        ];
    }
}
