<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Models\AuditCheckpoint;
use App\Models\AuditEvent;
use App\Services\Backups\SnapshotTags;
use App\Services\Crypto\Signer;
use App\Support\AuditChain;
use Illuminate\Support\Facades\DB;

/**
 * Signs the newest position of the audit chain (docs/09 R34,
 * protocol/SIGNING.md §7) with the control-plane signing key. Nothing
 * happens when no entry was added since the last checkpoint (unless forced
 * for an empty chain: then there is nothing to sign either).
 *
 * Holds the chain's advisory lock, so no entry commits between reading the
 * newest one and counting.
 */
final readonly class CreateAuditCheckpoint
{
    public function __construct(
        private Signer $signer,
    ) {}

    public function handle(): ?AuditCheckpoint
    {
        return DB::transaction(function (): ?AuditCheckpoint {
            DB::select('SELECT pg_advisory_xact_lock(?)', [RecordAuditEvent::LOCK_KEY]);

            $latest = AuditEvent::query()->orderByDesc('id')->first(['id', 'hash']);

            if ($latest === null) {
                return null;
            }

            $previous = AuditCheckpoint::query()->orderByDesc('event_id')->orderByDesc('id')->first();

            if ($previous !== null && $previous->event_id >= $latest->id) {
                return null;
            }

            $count = $previous === null
                ? AuditEvent::query()->where('id', '<=', $latest->id)->count()
                : $previous->event_count + AuditEvent::query()->where('id', '>', $previous->event_id)->where('id', '<=', $latest->id)->count();

            $createdAt = now()->toImmutable()->utc();
            $instance = SnapshotTags::instanceId();
            $signature = $this->signer->signAuditCheckpoint(
                AuditChain::checkpointMessage($count, $createdAt, $latest->id, $latest->hash, $instance),
            );

            return AuditCheckpoint::query()->create([
                'event_id' => $latest->id,
                'event_hash' => $latest->hash,
                'event_count' => $count,
                'instance_id' => $instance,
                'kid' => $this->signer->kid,
                'signature' => $signature,
                'created_at' => $createdAt,
            ]);
        });
    }
}
