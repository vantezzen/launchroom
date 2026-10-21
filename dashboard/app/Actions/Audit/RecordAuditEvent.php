<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Data\Audit\AuditEntryData;
use App\Jobs\Audit\DeliverAuditSinkJob;
use App\Models\AuditEvent;
use App\Models\AuditSink;
use App\Support\AuditChain;
use Illuminate\Support\Facades\DB;

/**
 * Appends one entry to the hash-chained audit log (docs/07, docs/09 R16).
 *
 * Runs inside the caller's transaction, so an audited change and its entry
 * commit or roll back together. A transaction-scoped advisory lock serializes
 * writers so the chain never forks.
 */
final class RecordAuditEvent
{
    /** Advisory lock key ("launchroom audit chain"). */
    public const int LOCK_KEY = 0x6C72_6175_6469_74;

    public function handle(AuditEntryData $entry): AuditEvent
    {
        return DB::transaction(function () use ($entry): AuditEvent {
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

            $prevHash = AuditEvent::query()->orderByDesc('id')->value('hash');
            $prevHash = is_string($prevHash) ? $prevHash : AuditChain::GENESIS;

            $createdAt = now()->toImmutable()->utc();

            $fields = [
                'team_id' => $entry->teamId,
                'actor_type' => $entry->actor->type->value,
                'actor_id' => $entry->actor->id,
                'action' => $entry->action->value,
                'subject_type' => $entry->subjectType,
                'subject_id' => $entry->subjectId,
                'ip' => $entry->actor->ip,
                'user_agent' => $entry->actor->userAgent,
                // Acting through an API token is part of the record (docs/16 §1.1).
                'meta' => $entry->actor->token === null ? $entry->meta : [...$entry->meta, 'via_token' => $entry->actor->token],
                'created_at' => $createdAt->format(AuditChain::TIME_FORMAT),
            ];

            // Real-time forwarding (docs/09 R34): every enabled sink, once committed.
            foreach (AuditSink::query()->where('enabled', true)->get(['id']) as $sink) {
                dispatch(new DeliverAuditSinkJob($sink->id))->afterCommit();
            }

            return AuditEvent::query()->create([
                ...$fields,
                'created_at' => $createdAt,
                'prev_hash' => $prevHash,
                'hash' => AuditChain::hash($prevHash, $fields),
            ]);
        });
    }
}
