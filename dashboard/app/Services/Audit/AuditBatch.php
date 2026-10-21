<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditCheckpoint;
use App\Models\AuditEvent;
use App\Support\AuditChain;
use Carbon\CarbonImmutable;

/**
 * One delivery: audit entries after a sink's cursor, in chain order, plus
 * the checkpoints that sign any of them (docs/09 R34). Each record is the
 * forwarding wire format (docs/15 §4): everything an outside verifier needs
 * with only the control plane's public key.
 */
final readonly class AuditBatch
{
    /**
     * @param  list<array<string, mixed>>  $records
     */
    public function __construct(
        public int $firstEventId,
        public int $lastEventId,
        public int $events,
        public CarbonImmutable $lastEventAt,
        public CarbonImmutable $firstEventAt,
        public array $records,
        public string $instance,
    ) {}

    /**
     * @param  list<AuditEvent>  $events  non-empty, ordered by id
     */
    public static function of(array $events, string $instance): self
    {
        $first = $events[0];
        $last = $events[count($events) - 1];
        $records = [];

        foreach ($events as $event) {
            $records[] = self::event($event);
        }

        $checkpoints = AuditCheckpoint::query()
            ->whereBetween('event_id', [$first->id, $last->id])
            ->orderBy('event_id')->orderBy('id')
            ->get();

        foreach ($checkpoints as $checkpoint) {
            $records[] = self::checkpoint($checkpoint);
        }

        return new self($first->id, $last->id, count($events), $last->created_at->toImmutable(), $first->created_at->toImmutable(), $records, $instance);
    }

    /**
     * @return array<string, mixed>
     */
    public static function event(AuditEvent $event): array
    {
        return [
            'type' => 'event',
            'id' => $event->id,
            'prevHash' => $event->prev_hash,
            'hash' => $event->hash,
            'fields' => AuditChain::fields($event),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function checkpoint(AuditCheckpoint $checkpoint): array
    {
        return [
            'type' => 'checkpoint',
            'id' => $checkpoint->id,
            'eventId' => $checkpoint->event_id,
            'count' => $checkpoint->event_count,
            'hash' => $checkpoint->event_hash,
            'createdAt' => $checkpoint->created_at->utc()->format(AuditChain::TIME_FORMAT),
            'instance' => $checkpoint->instance_id,
            'kid' => $checkpoint->kid,
            'signature' => $checkpoint->signature,
        ];
    }

    /**
     * One record as a single JSON line (no trailing newline).
     *
     * @param  array<string, mixed>  $record
     */
    public static function line(array $record): string
    {
        // `fields` exactly as hashed: an empty meta is `[]`, as the chain saw it.
        return json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
