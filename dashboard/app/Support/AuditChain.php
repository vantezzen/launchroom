<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditEvent;
use Carbon\CarbonInterface;

/**
 * The audit log's hash chain (docs/07 `audit_events`, docs/09 R34):
 *
 *     hash = sha256(prev_hash || "\n" || canonical_json(fields))
 *
 * `fields` are every stored column except id, prev_hash and hash, with
 * `created_at` as RFC 3339 UTC with microseconds. Changing, removing or
 * reordering any entry breaks every later hash.
 *
 * Checkpoints sign a position of the chain (protocol/SIGNING.md §7):
 *
 *     "launchroom-audit-checkpoint-v1\n" || canonical_json({count, createdAt, eventId, hash, instance})
 */
final class AuditChain
{
    public const string GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public const string TIME_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    public const string CHECKPOINT_PREFIX = "launchroom-audit-checkpoint-v1\n";

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function hash(string $prevHash, array $fields): string
    {
        return hash('sha256', $prevHash."\n".CanonicalJson::encode($fields));
    }

    /**
     * The hashed fields of a stored entry (also what forwarding sends, so a
     * copy can be verified outside launchroom).
     *
     * @return array<string, mixed>
     */
    public static function fields(AuditEvent $event): array
    {
        return [
            'team_id' => $event->team_id,
            'actor_type' => $event->actor_type->value,
            'actor_id' => $event->actor_id,
            'action' => $event->action->value,
            'subject_type' => $event->subject_type,
            'subject_id' => $event->subject_id,
            'ip' => $event->ip,
            'user_agent' => $event->user_agent,
            'meta' => $event->meta,
            'created_at' => $event->created_at->utc()->format(self::TIME_FORMAT),
        ];
    }

    /**
     * The exact bytes a checkpoint signature covers.
     */
    public static function checkpointMessage(int $count, CarbonInterface $createdAt, int $eventId, string $hash, ?string $instance): string
    {
        return self::CHECKPOINT_PREFIX.CanonicalJson::encode([
            'count' => $count,
            'createdAt' => $createdAt->copy()->utc()->format(self::TIME_FORMAT),
            'eventId' => $eventId,
            'hash' => $hash,
            'instance' => $instance,
        ]);
    }
}
