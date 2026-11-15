<?php

declare(strict_types=1);

namespace App\Actions\Connections;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\VariableType;
use App\Events\CanvasChanged;
use App\Exceptions\DomainRuleViolation;
use App\Models\Connection;
use App\Models\Service;
use App\Models\User;
use App\Services\Variables\VariableStore;
use App\Support\Connections\ConnectionVariables;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A canvas edge from a consumer to a provider (docs/06 §3.2). It injects
 * framework-aware reference variables into the consumer, owned by the
 * connection: Laravel `DB_*`, `DATABASE_URL`, `REDIS_*`, or `<SLUG>_URL`
 * (private `http://api:8000` or public URL) for app → app, or what the
 * provider declares itself (ProvidedVariables, D65). References are
 * environment-relative, so one connection works in every environment.
 */
final readonly class CreateConnection
{
    public function __construct(
        private ConnectionVariables $connectionVariables,
        private VariableStore $variables,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Service $from, Service $to, ConnectionMode $mode, ConnectionStyle $style, ?User $user, AuditActor $actor): Connection
    {
        $from->loadMissing('project');

        try {
            return $this->variables->write($from->project, function () use ($from, $to, $mode, $style, $user, $actor): Connection {
                $injected = $this->connectionVariables->for($from, $to, $mode, $style);

                $connection = Connection::query()->create([
                    'team_id' => $from->team_id,
                    'project_id' => $from->project_id,
                    'from_service_id' => $from->id,
                    'to_service_id' => $to->id,
                    'mode' => $mode,
                    'style' => $style,
                    'injected' => array_keys($injected),
                ]);

                foreach ($injected as $key => $value) {
                    $this->variables->create($from->project, $from, $key, $value, VariableType::Config, [], connection: $connection, actor: $user);
                }

                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::ConnectionCreated,
                    actor: $actor,
                    teamId: $from->team_id,
                    subjectType: 'connection',
                    subjectId: $connection->public_id,
                    meta: ['from' => $from->public_id, 'to' => $to->public_id, 'keys' => array_keys($injected)],
                ));
                CanvasChanged::forProject($from->project_id);

                return $connection;
            }, 'to');
        } catch (UniqueConstraintViolationException) {
            throw DomainRuleViolation::conflict('These services are already connected.');
        }
    }
}
