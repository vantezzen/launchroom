<?php

declare(strict_types=1);

namespace App\Models\Registry;

use App\Contracts\Crypto\SealedRow;
use App\Enums\Registry\RegistryCredentialStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasSealedSecret;
use App\Models\Team;
use App\Support\Images\ImageReference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A team's sign-in details for one upstream registry host (D91, docs/05 §6):
 * images on that host are copied into launchroom's registry under the team's
 * mirror path when they are deployed. The password or token is envelope
 * encrypted and only ever decrypted by App\Services\Crypto\SealedColumns,
 * for the copy itself; it never reaches a server, a task or Zot's config.
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property string $host
 * @property string $username
 * @property string $wrapped_dek
 * @property string $kek_id
 * @property string $secret
 * @property RegistryCredentialStatus $status
 * @property Carbon|null $last_checked_at
 * @property string|null $last_error
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable(['team_id', 'host', 'username', 'created_by'])]
#[Hidden(['wrapped_dek', 'kek_id', 'secret'])]
final class RegistryCredential extends Model implements SealedRow
{
    use HasPublicId;
    use HasSealedSecret;

    /**
     * A registry host as it appears in image references: lowercase, with a
     * dot, no scheme and no port (the team mirror path holds it,
     * Grammar::TEAM_MIRROR_REPOSITORY).
     */
    public const string HOST_PATTERN = '/^(?=.{4,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/D';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'unverified',
    ];

    public static function publicIdPrefix(): string
    {
        return 'rgc';
    }

    /**
     * The registry host a typed host means: Docker Hub's API names are
     * `docker.io` in image references.
     */
    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('#^https?://#', '', $host);
        $host = rtrim($host, '/');

        return in_array($host, ['index.docker.io', 'registry-1.docker.io', 'hub.docker.com'], true) ? 'docker.io' : $host;
    }

    public static function isValidHost(string $host): bool
    {
        return preg_match(self::HOST_PATTERN, $host) === 1;
    }

    /**
     * The team's credential for the registry an image is on, if any.
     */
    public static function forImage(int $teamId, ImageReference $reference): ?self
    {
        return self::query()->where('team_id', $teamId)->where('host', $reference->registry)->first();
    }

    public function markChecked(RegistryCredentialStatus $status, ?string $error = null): void
    {
        $this->forceFill([
            'status' => $status,
            'last_checked_at' => now(),
            'last_error' => $error === null ? null : mb_substr($error, 0, 1024),
        ])->save();
    }

    /**
     * New sign-in details haven't been checked yet (saved by the caller).
     */
    public function forgetCheck(): void
    {
        $this->status = RegistryCredentialStatus::Unverified;
        $this->last_error = null;
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RegistryCredentialStatus::class,
            'last_checked_at' => 'datetime',
        ];
    }

    public function sealWrapContext(): string
    {
        return 'registry_credential:'.$this->public_id;
    }

    public function sealAssociatedData(string $column): string
    {
        return match ($column) {
            'secret' => "registry_credential|{$this->team_id}|{$this->public_id}|secret",
            default => throw new InvalidArgumentException("{$column} is not a sealed column."),
        };
    }
}
