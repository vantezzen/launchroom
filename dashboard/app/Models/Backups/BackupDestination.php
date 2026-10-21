<?php

declare(strict_types=1);

namespace App\Models\Backups;

use App\Enums\Backups\DestinationStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Team;
use App\Services\Backups\DestinationSecrets;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An S3-compatible restic repository (docs/14 §3, docs/07 `backup_destinations`).
 * team_id null is the instance (control-plane) destination.
 *
 * Secrets (S3 credentials, the restic repository password) are envelope
 * encrypted and only ever decrypted by {@see DestinationSecrets}.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $team_id
 * @property string $name
 * @property string $kind
 * @property string $endpoint
 * @property string $bucket
 * @property string|null $region
 * @property string $prefix
 * @property bool $allow_private_network
 * @property bool $is_default
 * @property string $wrapped_dek
 * @property string $kek_id
 * @property string $credentials
 * @property string $repository_password
 * @property DestinationStatus $status
 * @property Carbon|null $last_checked_at
 * @property string|null $last_error
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $team
 */
#[Fillable(['team_id', 'name', 'kind', 'endpoint', 'bucket', 'region', 'prefix', 'allow_private_network', 'is_default', 'created_by'])]
#[Hidden(['wrapped_dek', 'kek_id', 'credentials', 'repository_password'])]
final class BackupDestination extends Model
{
    use HasPublicId;

    /** @var array<string, mixed> */
    protected $attributes = [
        'kind' => 's3',
        'prefix' => '',
        'status' => 'unverified',
        'allow_private_network' => false,
        'is_default' => false,
    ];

    public static function publicIdPrefix(): string
    {
        return 'bkd';
    }

    public function isInstanceDestination(): bool
    {
        return $this->team_id === null;
    }

    /**
     * Records the outcome of a connection test or a backup that used this destination.
     */
    public function markChecked(DestinationStatus $status, ?string $error = null): void
    {
        $this->forceFill(['last_error' => $error === null ? null : mb_substr($error, 0, 1024), 'last_checked_at' => now()]);
        $this->status = $status;
        $this->save();
    }

    public function markChecking(): void
    {
        $this->status = DestinationStatus::Checking;
        $this->save();
    }

    /**
     * The instance (control-plane) destination, if configured.
     */
    public static function forInstance(): ?self
    {
        return self::query()->whereNull('team_id')->first();
    }

    /**
     * The team's default destination (new schedules use it).
     */
    public static function defaultFor(int $teamId): ?self
    {
        return self::query()->where('team_id', $teamId)->where('is_default', true)->first()
            ?? self::query()->where('team_id', $teamId)->orderBy('id')->first();
    }

    /**
     * restic repository string (docs/14 §2): s3:<endpoint>/<bucket>[/<prefix>].
     */
    public function repository(): string
    {
        $prefix = trim($this->prefix, '/');

        return 's3:'.rtrim($this->endpoint, '/').'/'.$this->bucket.($prefix === '' ? '' : '/'.$prefix);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<BackupSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(BackupSchedule::class, 'destination_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allow_private_network' => 'boolean',
            'is_default' => 'boolean',
            'status' => DestinationStatus::class,
            'last_checked_at' => 'datetime',
        ];
    }
}
