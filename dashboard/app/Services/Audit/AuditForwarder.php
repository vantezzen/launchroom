<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\Audit\AuditSinkKind;
use App\Models\AuditEvent;
use App\Models\AuditSink;
use App\Services\Audit\Transports\AuditSinkTransport;
use App\Services\Audit\Transports\S3Transport;
use App\Services\Audit\Transports\SyslogTransport;
use App\Services\Audit\Transports\WebhookTransport;
use App\Services\Backups\SnapshotTags;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers the audit log to one sink from its cursor (docs/09 R34): in
 * chain order, at least once, batch by batch until it is caught up or the
 * time budget is spent. A failure stops the run and backs off (30 s
 * doubling to 1 h); the entries stay in the database, so a sink that was
 * down catches up. S3 sinks wait for a fuller object (1000 entries or the
 * oldest pending one 5 minutes old) unless forced.
 */
final readonly class AuditForwarder
{
    public const int BACKOFF_BASE_SECONDS = 30;

    public const int BACKOFF_MAX_SECONDS = 3600;

    public const int S3_MAX_WAIT_SECONDS = 300;

    private const int TIME_BUDGET_SECONDS = 45;

    public function __construct(
        private Container $container,
    ) {}

    public function transport(AuditSinkKind $kind): AuditSinkTransport
    {
        return $this->container->make(match ($kind) {
            AuditSinkKind::Webhook => WebhookTransport::class,
            AuditSinkKind::Syslog => SyslogTransport::class,
            AuditSinkKind::S3 => S3Transport::class,
        });
    }

    /**
     * @param  bool  $force  ignore the backoff and the S3 wait ("Retry now")
     */
    public function deliver(int $sinkId, bool $force = false): void
    {
        $lock = Cache::lock("lr:audit-sink:{$sinkId}", 120);

        if (! $lock->get()) {
            return;
        }

        try {
            $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;

            do {
                $sink = AuditSink::query()->find($sinkId);

                if ($sink === null || ! $sink->enabled) {
                    return;
                }

                if (! $force && $sink->next_attempt_at !== null && $sink->next_attempt_at->isFuture()) {
                    return;
                }

                $limit = $sink->kind->batchSize();
                /** @var list<AuditEvent> $events */
                $events = AuditEvent::query()->where('id', '>', $sink->cursor_event_id)->orderBy('id')->limit($limit)->get()->all();

                if ($events === []) {
                    return;
                }

                if ($sink->kind === AuditSinkKind::S3 && ! $force && count($events) < $limit
                    && $events[0]->created_at->greaterThan(now()->subSeconds(self::S3_MAX_WAIT_SECONDS))) {
                    return;
                }

                if (! $this->send($sink, AuditBatch::of($events, SnapshotTags::instanceId()))) {
                    return;
                }

                $force = false;
            } while (count($events) === $limit && microtime(true) < $deadline);
        } finally {
            $lock->release();
        }
    }

    /**
     * Pending entries after the cursor (for "behind by").
     */
    public function behind(AuditSink $sink): int
    {
        return AuditEvent::query()->where('id', '>', $sink->cursor_event_id)->count();
    }

    public static function backoffSeconds(int $failures): int
    {
        return min(self::BACKOFF_BASE_SECONDS * (2 ** max(0, min($failures - 1, 12))), self::BACKOFF_MAX_SECONDS);
    }

    private function send(AuditSink $sink, AuditBatch $batch): bool
    {
        $sink->last_attempt_at = now()->toImmutable();

        try {
            $this->transport($sink->kind)->deliver($sink, $batch);
        } catch (AuditDeliveryFailed $e) {
            $this->failed($sink, $e->getMessage());

            return false;
        } catch (DecryptionFailed) {
            $this->failed($sink, 'The stored credentials could not be opened. Enter them again.');

            return false;
        } catch (Throwable $e) {
            // Never the message: it could carry an address or a credential.
            Log::warning('audit sink delivery failed', ['sink' => $sink->public_id, 'exception' => $e::class]);
            $this->failed($sink, 'Delivery failed unexpectedly. We will try again.');

            return false;
        }

        $sink->forceFill([
            'cursor_event_id' => $batch->lastEventId,
            'delivered_through_at' => $batch->lastEventAt,
            'last_success_at' => now(),
            'last_error' => null,
            'failures' => 0,
            'next_attempt_at' => null,
        ])->save();

        return true;
    }

    private function failed(AuditSink $sink, string $message): void
    {
        $failures = $sink->failures + 1;

        $sink->forceFill([
            'failures' => $failures,
            'last_error' => mb_substr($message, 0, 500),
            'next_attempt_at' => now()->addSeconds(self::backoffSeconds($failures)),
        ])->save();
    }
}
