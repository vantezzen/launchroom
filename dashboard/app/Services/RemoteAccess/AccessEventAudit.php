<?php

declare(strict_types=1);

namespace App\Services\RemoteAccess;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\RemoteAccess\RemoteAccess;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Audit entries for what happened at an access endpoint (docs/09 R39):
 * every authenticated connection, failed logins per address, a lockout.
 * Never credentials, commands or contents.
 */
final readonly class AccessEventAudit
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array<mixed>  $events
     */
    public function record(RemoteAccess $access, array $events): void
    {
        $access->loadMissing('serviceInstance.service', 'server');

        foreach (array_slice($events, 0, 50) as $event) {
            if (! is_array($event)) {
                continue;
            }

            $type = is_string($event['type'] ?? null) ? $event['type'] : '';
            $action = match ($type) {
                'connected' => AuditAction::AccessConnected,
                'auth_failed' => AuditAction::AccessAuthFailed,
                'locked' => AuditAction::AccessLocked,
                default => null,
            };

            if ($action === null) {
                continue;
            }

            if ($type === 'connected') {
                $access->connections++;
            }

            $this->audit->handle(new AuditEntryData(
                action: $action,
                actor: $access->server !== null ? AuditActor::server($access->server) : AuditActor::system(),
                teamId: $access->team_id,
                subjectType: 'service',
                subjectId: $access->serviceInstance->service->public_id,
                meta: array_filter([
                    'access' => $access->public_id,
                    'kind' => $access->kind->value,
                    'remote' => is_string($event['remote'] ?? null) && filter_var($event['remote'], FILTER_VALIDATE_IP) !== false ? $event['remote'] : null,
                    'method' => is_string($event['method'] ?? null) && in_array($event['method'], ['password', 'publickey'], true) ? $event['method'] : null,
                    'channel' => is_string($event['channel'] ?? null) && in_array($event['channel'], ['shell', 'exec', 'sftp', 'database'], true) ? $event['channel'] : null,
                    'count' => is_int($event['count'] ?? null) ? $event['count'] : null,
                    'at' => is_string($event['at'] ?? null) ? $this->time($event['at'])?->toIso8601String() : null,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ));
        }

        if ($access->isDirty()) {
            $access->save();
        }
    }

    private function time(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
