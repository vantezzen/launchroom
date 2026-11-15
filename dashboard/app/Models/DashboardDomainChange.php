<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Domains\DnsCheckData;
use App\Data\Instance\DashboardCertificateData;
use App\Data\Instance\DashboardProbeData;
use App\Enums\Contracts\HasTransitionTable;
use App\Enums\DashboardDomainStatus;
use App\Models\Concerns\HasStatusTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to move the dashboard to its own domain (Settings → Dashboard
 * domain, docs/05 §4.1, D43): the checks' latest results and, once switched,
 * when and by whom. Instance-level (no team).
 *
 * @property int $id
 * @property string $hostname
 * @property DashboardDomainStatus $status
 * @property string $previous_url the canonical origin when the change started
 * @property int|null $requested_by
 * @property DnsCheckData|null $dns
 * @property DashboardCertificateData|null $certificate
 * @property DashboardProbeData|null $probe
 * @property Carbon|null $checked_at
 * @property Carbon|null $ready_at
 * @property Carbon|null $switched_at
 * @property int|null $switched_by
 * @property Carbon|null $ended_at
 * @property string|null $ended_reason cancelled|switched_back|replaced|reset
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $requester
 */
#[Fillable(['hostname', 'previous_url', 'requested_by'])]
final class DashboardDomainChange extends Model
{
    /** @use HasStatusTransitions<DashboardDomainStatus> */
    use HasStatusTransitions;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * The change being set up (pending or ready), if any.
     */
    public static function open(): ?self
    {
        return self::query()
            ->whereIn('status', [DashboardDomainStatus::Pending->value, DashboardDomainStatus::Ready->value])
            ->first();
    }

    /**
     * The domain the dashboard runs at, if it was switched through Settings.
     */
    public static function active(): ?self
    {
        return self::query()->where('status', DashboardDomainStatus::Active->value)->first();
    }

    /**
     * @param  DashboardDomainStatus  $from
     * @param  DashboardDomainStatus  $to
     */
    protected function recordTransition(HasTransitionTable $from, HasTransitionTable $to, ?string $note): void
    {
        match ($to) {
            DashboardDomainStatus::Ready => $this->forceFill(['ready_at' => now()]),
            DashboardDomainStatus::Pending => $this->forceFill(['ready_at' => null]),
            DashboardDomainStatus::Active => $this->forceFill(['switched_at' => now()]),
            DashboardDomainStatus::Cancelled, DashboardDomainStatus::Reverted => $this->forceFill(['ended_at' => now(), 'ended_reason' => $note]),
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DashboardDomainStatus::class,
            'dns' => DnsCheckData::class,
            'certificate' => DashboardCertificateData::class,
            'probe' => DashboardProbeData::class,
            'checked_at' => 'datetime',
            'ready_at' => 'datetime',
            'switched_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
