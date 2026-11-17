<?php

declare(strict_types=1);

namespace App\Models\Registry;

use App\Enums\Contracts\HasTransitionTable;
use App\Enums\Registry\RegistryCopyStatus;
use App\Models\Concerns\HasStatusTransitions;
use App\Models\Team;
use App\Support\Images\RegistryLayout;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One private image copied into launchroom's registry (D91, docs/05 §6):
 * `source` (`<host>/<repository>`) at `reference` (a tag or a digest) is
 * copied by digest into the team's mirror path
 * `mirror/team_<team id>/<host>/<repository>`, which servers pull from
 * with team-scoped tokens.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $registry_credential_id
 * @property string $source
 * @property string $reference
 * @property string|null $digest
 * @property RegistryCopyStatus $status
 * @property string|null $message
 * @property int|null $bytes
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read RegistryCredential|null $credential
 */
#[Fillable(['team_id', 'registry_credential_id', 'source', 'reference'])]
final class RegistryImageCopy extends Model
{
    /** @use HasStatusTransitions<RegistryCopyStatus> */
    use HasStatusTransitions;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * The repository in launchroom's registry (no host):
     * `mirror/team_01j…/ghcr.io/acme/api`.
     */
    public static function mirrorRepository(Team $team, string $source): string
    {
        return strtolower(RegistryLayout::MIRROR.'/'.$team->public_id.'/'.$source);
    }

    /**
     * The digest-pinned reference servers run, once copied.
     */
    public function image(): ?string
    {
        return $this->status === RegistryCopyStatus::Succeeded && $this->digest !== null
            ? RegistryLayout::registry().self::mirrorRepository($this->team, $this->source).'@'.$this->digest
            : null;
    }

    /**
     * @param  RegistryCopyStatus  $from
     * @param  RegistryCopyStatus  $to
     */
    protected function recordTransition(HasTransitionTable $from, HasTransitionTable $to, ?string $note): void
    {
        match ($to) {
            RegistryCopyStatus::Pending => $this->forceFill(['message' => null, 'started_at' => null, 'finished_at' => null]),
            RegistryCopyStatus::Copying => $this->forceFill(['started_at' => now(), 'message' => null]),
            RegistryCopyStatus::Succeeded => $this->forceFill(['finished_at' => now(), 'message' => null]),
            RegistryCopyStatus::Failed => $this->forceFill(['finished_at' => now(), 'message' => $note]),
        };
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<RegistryCredential, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(RegistryCredential::class, 'registry_credential_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RegistryCopyStatus::class,
            'bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
