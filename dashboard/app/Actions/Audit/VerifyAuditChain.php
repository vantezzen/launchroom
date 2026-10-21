<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Data\Audit\AuditChainVerification;
use App\Enums\Audit\AuditChainProblem;
use App\Models\AuditCheckpoint;
use App\Models\AuditEvent;
use App\Services\Crypto\ControlPlaneKeys;
use App\Support\AuditChain;
use App\Support\Crypto\PublicKeyFormat;
use Carbon\CarbonImmutable;

/**
 * Recomputes the audit hash chain and checks every signed checkpoint
 * (docs/09 R34). Full mode starts at the genesis hash; incremental mode
 * starts at the newest checkpoint whose signature and entry still verify
 * (falling back to full when there is none). Stops at the first problem and
 * names it: an edited entry, a broken link (an entry removed, inserted or
 * reordered before it), a truncated tail (shorter than a checkpoint says),
 * a forged checkpoint or one that doesn't match the chain.
 */
final readonly class VerifyAuditChain
{
    public function __construct(
        private ControlPlaneKeys $keys,
    ) {}

    public function handle(bool $fromCheckpoint = false): AuditChainVerification
    {
        $trusted = [];

        foreach ($this->keys->historical() as $kid => $formatted) {
            $raw = PublicKeyFormat::parseEd25519($formatted);

            if ($raw !== null) {
                $trusted[$kid] = $raw;
            }
        }

        $expectedPrev = AuditChain::GENESIS;
        $count = 0;
        $afterId = 0;
        $anchored = null;
        $incremental = false;
        $checkpointsChecked = 0;

        if ($fromCheckpoint && ($start = $this->startingPoint($trusted)) !== null) {
            [$checkpoint, $event] = $start;
            $expectedPrev = $checkpoint->event_hash;
            $count = $checkpoint->event_count;
            $afterId = $checkpoint->event_id;
            $anchored = $event->created_at->toImmutable();
            $incremental = true;
            $checkpointsChecked = 1;
        }

        /** @var array<int, list<AuditCheckpoint>> $pending */
        $pending = [];

        foreach (AuditCheckpoint::query()->where('event_id', '>', $afterId)->orderBy('event_id')->orderBy('id')->cursor() as $checkpoint) {
            $pending[$checkpoint->event_id][] = $checkpoint;
        }

        $checked = 0;
        $lastId = $afterId > 0 ? $afterId : null;
        // By reference: the counters at the time of the failure.
        $fail = function (AuditChainProblem $problem, string $message, ?int $eventId, ?int $checkpointId = null) use (&$checked, &$checkpointsChecked, &$anchored, &$lastId, $incremental): AuditChainVerification {
            return new AuditChainVerification(
                valid: false, checked: $checked, firstInvalidId: $eventId, problem: $problem, message: $message,
                checkpointId: $checkpointId, checkpoints: $checkpointsChecked, verifiedUpTo: $anchored, lastEventId: $lastId, incremental: $incremental,
            );
        };

        foreach (AuditEvent::query()->where('id', '>', $afterId)->lazyById(500) as $event) {
            if (! hash_equals($expectedPrev, $event->prev_hash)) {
                return $fail(AuditChainProblem::BrokenLink, "Entry #{$event->id} doesn't follow the entry before it: an entry was removed, added or moved.", $event->id);
            }

            if (! hash_equals(AuditChain::hash($event->prev_hash, AuditChain::fields($event)), $event->hash)) {
                return $fail(AuditChainProblem::Edited, "Entry #{$event->id} was changed after it was written.", $event->id);
            }

            $count++;
            $checked++;
            $expectedPrev = $event->hash;
            $lastId = $event->id;

            foreach ($pending[$event->id] ?? [] as $checkpoint) {
                if (! $this->signed($checkpoint, $trusted)) {
                    return $fail(AuditChainProblem::ForgedCheckpoint, "Checkpoint #{$checkpoint->id} isn't signed by this control plane.", $event->id, $checkpoint->id);
                }

                if ($checkpoint->event_count !== $count || ! hash_equals($checkpoint->event_hash, $event->hash)) {
                    return $fail(AuditChainProblem::CheckpointMismatch, "Checkpoint #{$checkpoint->id} doesn't match entry #{$event->id}: earlier entries were changed, removed or added.", $event->id, $checkpoint->id);
                }

                $checkpointsChecked++;
                $anchored = $event->created_at->toImmutable();
            }

            unset($pending[$event->id]);
        }

        // Checkpoints whose entry never came: the tail was cut, or the entry is gone.
        foreach ($pending as $eventId => $checkpoints) {
            $checkpoint = $checkpoints[0];

            if (! $this->signed($checkpoint, $trusted)) {
                return $fail(AuditChainProblem::ForgedCheckpoint, "Checkpoint #{$checkpoint->id} isn't signed by this control plane.", null, $checkpoint->id);
            }

            if ($lastId === null || $eventId > $lastId) {
                $after = $lastId === null ? 'Every entry was removed' : "Entries after #{$lastId} were removed";

                return $fail(AuditChainProblem::Truncated, "{$after}: checkpoint #{$checkpoint->id} signed entry #{$eventId}.", $lastId, $checkpoint->id);
            }

            return $fail(AuditChainProblem::CheckpointMismatch, "Checkpoint #{$checkpoint->id} signed entry #{$eventId}, which is missing.", $eventId, $checkpoint->id);
        }

        return new AuditChainVerification(
            valid: true, checked: $checked, checkpoints: $checkpointsChecked, verifiedUpTo: $anchored,
            lastEventId: $lastId, incremental: $incremental,
        );
    }

    /**
     * The newest checkpoint that still holds: signed, and its entry is there
     * with the same hash.
     *
     * @param  array<string, string>  $trusted
     * @return array{0: AuditCheckpoint, 1: AuditEvent}|null
     */
    private function startingPoint(array $trusted): ?array
    {
        $checkpoint = AuditCheckpoint::query()->orderByDesc('event_id')->orderByDesc('id')->first();

        if ($checkpoint === null || ! $this->signed($checkpoint, $trusted)) {
            return null;
        }

        $event = AuditEvent::query()->find($checkpoint->event_id);

        if ($event === null || ! hash_equals($checkpoint->event_hash, $event->hash)
            || ! hash_equals(AuditChain::hash($event->prev_hash, AuditChain::fields($event)), $event->hash)) {
            return null;
        }

        return [$checkpoint, $event];
    }

    /**
     * @param  array<string, string>  $trusted  kid => raw Ed25519 public key
     */
    private function signed(AuditCheckpoint $checkpoint, array $trusted): bool
    {
        $key = $trusted[$checkpoint->kid] ?? null;
        $signature = base64_decode($checkpoint->signature, true);

        if ($key === null || $key === '' || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        $message = AuditChain::checkpointMessage(
            $checkpoint->event_count,
            CarbonImmutable::instance($checkpoint->created_at),
            $checkpoint->event_id,
            $checkpoint->event_hash,
            $checkpoint->instance_id,
        );

        return sodium_crypto_sign_verify_detached($signature, $message, $key);
    }
}
