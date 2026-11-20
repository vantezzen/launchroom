<?php

declare(strict_types=1);

namespace App\Models\Moves;

use App\Data\Moves\DnsSwitchSnapshotData;
use App\Data\Moves\HandoverManifestData;
use App\Data\Moves\MoveReceiverData;
use App\Enums\Contracts\HasTransitionTable;
use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\HasStatusTransitions;
use App\Models\Server;
use App\Models\ServerEnrollment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Settings → Move launchroom (docs/14 §13.4, D56): the instance moves to a
 * new machine that installed with `install.sh --move <token>`. Instance
 * level (no team): only instance owners see and drive it.
 *
 * Secrets: only the SHA-256 of the token's secret is stored; the new
 * machine's join token and the hand-over keys exist only sealed to the
 * new machine's X25519 key (crypto_box_seal), which this dashboard can't
 * open. All are hidden.
 *
 * @property int $id
 * @property string $public_id
 * @property ControlPlaneMoveStatus $status
 * @property int|null $source_server_id the control-plane server it moves away from
 * @property int|null $target_server_id the new machine, once its launchroomd enrolled
 * @property int|null $server_move_id the workload move (source → target)
 * @property string $token_hash
 * @property CarbonImmutable $token_expires_at
 * @property string|null $receiver_public_key X25519, base64
 * @property string|null $claim_code
 * @property MoveReceiverData|null $receiver
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $receiver_seen_at
 * @property int|null $enrollment_id
 * @property string|null $sealed_join_token
 * @property HandoverManifestData|null $handover the hand-over manifest
 * @property DnsSwitchSnapshotData|null $dns_switch the DNS records to change, taken when the hand-over starts
 * @property CarbonImmutable|null $frozen_at
 * @property CarbonImmutable|null $handed_over_at
 * @property string|null $new_url
 * @property string|null $error
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Server|null $source
 * @property-read Server|null $target
 * @property-read ServerMove|null $serverMove
 * @property-read ServerEnrollment|null $enrollment
 */
#[Fillable(['source_server_id', 'created_by'])]
#[Hidden(['token_hash', 'sealed_join_token', 'handover'])]
final class ControlPlaneMove extends Model
{
    use HasPublicId;

    /** @use HasStatusTransitions<ControlPlaneMoveStatus> */
    use HasStatusTransitions;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'waiting_for_server',
    ];

    public static function publicIdPrefix(): string
    {
        return 'cpm';
    }

    /**
     * The open move, if any (at most one, partial unique index).
     */
    public static function open(): ?self
    {
        return self::query()
            ->whereIn('status', ControlPlaneMoveStatus::nonTerminal())
            ->latest('id')
            ->first();
    }

    /**
     * @param  ControlPlaneMoveStatus  $from
     * @param  ControlPlaneMoveStatus  $to
     */
    protected function recordTransition(HasTransitionTable $from, HasTransitionTable $to, ?string $note): void
    {
        $this->error = $note;

        if ($to->isTerminal()) {
            $this->forceFill(['finished_at' => now(), 'sealed_join_token' => null]);
        }
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'source_server_id');
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'target_server_id');
    }

    /**
     * @return BelongsTo<ServerMove, $this>
     */
    public function serverMove(): BelongsTo
    {
        return $this->belongsTo(ServerMove::class);
    }

    /**
     * @return BelongsTo<ServerEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(ServerEnrollment::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ControlPlaneMoveStatus::class,
            'token_expires_at' => 'datetime',
            'receiver' => MoveReceiverData::class,
            'claimed_at' => 'datetime',
            'receiver_seen_at' => 'datetime',
            'handover' => HandoverManifestData::class,
            'dns_switch' => DnsSwitchSnapshotData::class,
            'frozen_at' => 'datetime',
            'handed_over_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
