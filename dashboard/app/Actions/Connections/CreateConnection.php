<?php

declare(strict_types=1);

namespace App\Actions\Connections;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Services\DatabaseSource;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\AuditAction;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\VariableType;
use App\Exceptions\DomainRuleViolation;
use App\Models\Connection;
use App\Models\Service;
use App\Models\User;
use App\Services\Variables\VariableStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A canvas edge from a consumer to a provider (docs/06 §3.2). It injects
 * framework-aware reference variables into the consumer, owned by the
 * connection: Laravel `DB_*`, `DATABASE_URL`, `REDIS_*`, or `<SLUG>_URL`
 * (private `http://api:8000` or public URL) for app → app. References are
 * environment-relative, so one connection works in every environment.
 */
final readonly class CreateConnection
{
    public function __construct(
        private DatabaseEngines $engines,
        private VariableStore $variables,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Service $from, Service $to, ConnectionMode $mode, ConnectionStyle $style, ?User $user, AuditActor $actor): Connection
    {
        if ($from->project_id !== $to->project_id || $from->id === $to->id) {
            throw DomainRuleViolation::on('to', 'Connect two different services of the same project.');
        }

        $injected = $this->variablesFor($to, $mode, $style);

        try {
            return DB::transaction(function () use ($from, $to, $mode, $style, $user, $actor, $injected): Connection {
                $from->loadMissing('project');

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

                return $connection;
            });
        } catch (UniqueConstraintViolationException) {
            throw DomainRuleViolation::conflict('These services are already connected.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function variablesFor(Service $to, ConnectionMode $mode, ConnectionStyle $style): array
    {
        $source = $to->source;

        if ($source instanceof DatabaseSource) {
            if ($mode === ConnectionMode::Public) {
                throw DomainRuleViolation::on('mode', 'Databases are private (docs: public database access is an admin-only toggle).');
            }

            return $this->engines->for($source->engine)->connectionVariables($style, $to->slug);
        }

        if (! $to->kind->isWeb()) {
            throw DomainRuleViolation::on('to', 'Workers and crons have no address to connect to.');
        }

        $key = strtoupper(str_replace('-', '_', $to->slug)).'_URL';
        $exported = $mode === ConnectionMode::Public ? 'PUBLIC_URL' : 'PRIVATE_URL';

        return [$key => "\${{{$to->slug}.{$exported}}}"];
    }
}
