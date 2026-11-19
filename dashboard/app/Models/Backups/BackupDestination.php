<?php

declare(strict_types=1);

namespace App\Models\Backups;

use App\Contracts\Crypto\SealedRow;
use App\Enums\Backups\DestinationErrorKind;
use App\Enums\Backups\DestinationStatus;
use App\Events\Broadcasts\BackupsChanged;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasSealedSecret;
use App\Models\ServiceInstance;
use App\Models\Team;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * An S3-compatible restic repository (docs/14 §3, docs/07 `backup_destinations`).
 * team_id null is the instance (control-plane) destination.
 *
 * Secrets (S3 credentials, the restic repository password) are envelope
 * encrypted and only ever decrypted by App\Services\Crypto\SealedColumns.
 *
 * Removing a destination keeps its row (`removed_at`, soft delete) with its
 * access keys wiped but its repository password kept: the runs, restores,
 * point-in-time archives and verifications that used it stay as read-only
 * history, the backups stay in the bucket, and adding the same bucket and
 * folder again reconnects them with that password (DeleteBackupDestination,
 * CreateBackupDestination). Queries see only current
 * destinations unless they ask for removed ones; history relations include them.
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
 * @property DestinationErrorKind|null $error_kind why the last test or backup failed
 * @property int|null $copied_from_id launchroom's own destination: the team destination it was copied from
 * @property Carbon|null $removed_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $team
 */
#[Fillable(['team_id', 'name', 'kind', 'endpoint', 'bucket', 'region', 'prefix', 'allow_private_network', 'is_default', 'created_by'])]
#[Hidden(['wrapped_dek', 'kek_id', 'credentials', 'repository_password'])]
final class BackupDestination extends Model implements SealedRow
{
    use HasPublicId;
    use HasSealedSecret;
    use SoftDeletes;

    public const string DELETED_AT = 'removed_at';

    public const string LOCATION_TAKEN = 'Another backup storage already uses this bucket and folder. Choose another folder: two storages can’t share one backup repository.';

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

    /**
     * KEK rotation includes removed destinations: they keep their repository
     * password for reconnecting and disaster recovery.
     *
     * @return Builder<self>
     */
    public static function sealedRows(): Builder
    {
        return self::withTrashed()->whereNotNull('wrapped_dek')->where('wrapped_dek', '!=', '');
    }

    /**
     * launchroom still has the repository password (always for current
     * storage; removed storage keeps it until it is added again).
     */
    public function keepsRepositoryPassword(): bool
    {
        $dek = $this->getAttribute('wrapped_dek');
        $password = $this->getAttribute('repository_password');

        return is_string($dek) && $dek !== '' && is_string($password) && $password !== '';
    }

    /**
     * Removed storage of the same scope at this bucket and folder whose
     * repository password launchroom still has (newest removal first):
     * adding the location again reuses that password.
     */
    public static function removedAt(?int $teamId, string $endpoint, string $bucket, string $prefix): ?self
    {
        $location = self::location($endpoint, $bucket, $prefix);

        return self::onlyTrashed()
            ->where('bucket', $bucket)
            ->when($teamId === null, static fn (Builder $query) => $query->whereNull('team_id'), static fn (Builder $query) => $query->where('team_id', $teamId))
            ->whereNotNull('repository_password')->where('repository_password', '!=', '')
            ->where('wrapped_dek', '!=', '')
            ->latest('removed_at')
            ->get()
            ->first(static fn (self $old): bool => self::location($old->endpoint, $old->bucket, $old->prefix) === $location);
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
        $this->forceFill([
            'last_error' => $error === null ? null : mb_substr($error, 0, 1024),
            'error_kind' => $status === DestinationStatus::Failing ? (DestinationErrorKind::classify($error) ?? DestinationErrorKind::Other) : null,
            'last_checked_at' => now(),
        ]);
        $this->status = $status;
        $this->save();
        BackupsChanged::about($this->team_id);
    }

    public function markChecking(): void
    {
        $this->status = DestinationStatus::Checking;
        $this->save();
        BackupsChanged::about($this->team_id);
    }

    /**
     * The instance (control-plane) destination: no team, at most one
     * (CreateBackupDestination refuses a second).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function instanceWide(Builder $query): void
    {
        $query->whereNull('team_id');
    }

    public static function forInstance(): ?self
    {
        return self::query()->instanceWide()->first();
    }

    /**
     * Where a backup of $instance goes: its schedule's destination (which
     * may be one shared by the whole instance), else the team's default.
     * Removed destinations are never used.
     */
    public static function forService(ServiceInstance $instance, ?BackupSchedule $schedule = null): ?self
    {
        $schedule ??= BackupSchedule::ofInstance($instance);

        return self::query()->whereKey($schedule->destination_id ?? 0)->first() ?? self::defaultFor($instance->team_id);
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
     * The location as compared for "two destinations on one repository":
     * endpoint without trailing slash, case-insensitive host, the bucket and
     * the folder without surrounding slashes.
     *
     * @return array{string, string, string}
     */
    public static function location(string $endpoint, string $bucket, string $prefix): array
    {
        return [mb_strtolower(rtrim($endpoint, '/')), $bucket, trim($prefix, '/')];
    }

    /**
     * A current destination (any scope, any team) that already uses this
     * bucket and folder: two repository passwords can't share one restic
     * repository.
     */
    public static function usingLocation(string $endpoint, string $bucket, string $prefix, ?int $except = null): ?self
    {
        [$endpoint, $bucket, $prefix] = self::location($endpoint, $bucket, $prefix);

        return self::query()
            ->where('bucket', $bucket)
            ->when($except !== null, static fn (Builder $query) => $query->whereKeyNot($except))
            ->get()
            ->first(static fn (self $destination): bool => self::location($destination->endpoint, $destination->bucket, $destination->prefix) === [$endpoint, $bucket, $prefix]);
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
     * launchroom's own destination: the team destination it was copied from.
     *
     * @return BelongsTo<self, $this>
     */
    public function copiedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'copied_from_id');
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
            'error_kind' => DestinationErrorKind::class,
            'last_checked_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function sealWrapContext(): string
    {
        return 'backup_destination:'.$this->public_id;
    }

    public function sealAssociatedData(string $column): string
    {
        return match ($column) {
            'credentials', 'repository_password' => 'backup_destination|'.($this->team_id ?? 'instance')."|{$this->public_id}|{$column}",
            default => throw new InvalidArgumentException("{$column} is not a sealed column."),
        };
    }
}
