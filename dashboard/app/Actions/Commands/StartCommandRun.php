<?php

declare(strict_types=1);

namespace App\Actions\Commands;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Commands\CommandData;
use App\Data\Commands\StartCommandRunInput;
use App\Data\Specs\ServiceSpec;
use App\Enums\AuditAction;
use App\Enums\Commands\CommandRunStatus;
use App\Enums\ServiceKind;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Models\Commands\CommandRun;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Queries\Commands\CommandCatalogue;
use App\Services\Commands\CommandPlacement;
use App\Services\Tasks\RunOnceTasks;
use Illuminate\Support\Facades\DB;

/**
 * Runs a one-off command (D105, docs/03 §7.5): a `run_once` task in the
 * live deployment's image, with its variables (sealed to the server at
 * lease time), network and volumes, the argv as given (never a shell), a
 * timeout and bounded output. Recorded with who ran which argv, audited
 * without any env value. The caller has authorized the user.
 */
final readonly class StartCommandRun
{
    public function __construct(
        private CommandCatalogue $catalogue,
        private CommandPlacement $placement,
        private RunOnceTasks $runOnce,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ServiceInstance $instance, StartCommandRunInput $input, User $user, AuditActor $actor): CommandRun
    {
        if (config('launchroom.commands.enabled') !== true) {
            throw DomainRuleViolation::conflict('Commands are turned off on this launchroom.');
        }

        $instance->loadMissing(['service', 'environment', 'activeDeployment.revision']);

        if ($instance->service->kind === ServiceKind::Database) {
            throw DomainRuleViolation::conflict('Databases have no commands. Use the Console or the Data tab.');
        }

        [$argv, $name] = $this->argv($instance, $input);
        $deployment = $instance->activeDeployment;
        $spec = $deployment?->revision?->spec->service($instance->service->public_id);

        if (! $deployment instanceof Deployment || $deployment->image === null || ! $spec instanceof ServiceSpec) {
            throw DomainRuleViolation::conflict('Commands run in the live version. Deploy this service first.');
        }

        $target = $this->placement->for($instance, $spec);
        $timeout = $input->timeoutSeconds ?? config()->integer('launchroom.commands.default_timeout_seconds');
        $timeout = min($timeout, config()->integer('launchroom.commands.max_timeout_seconds'));

        $run = DB::transaction(function () use ($instance, $user, $actor, $deployment, $spec, $target, $argv, $name, $timeout): CommandRun {
            // Serialize starts of this service so the limit holds.
            ServiceInstance::query()->whereKey($instance->id)->lockForUpdate()->value('id');
            $open = array_map(static fn (CommandRunStatus $status): string => $status->value, CommandRunStatus::nonTerminal());

            $perService = config()->integer('launchroom.commands.max_open_per_service');
            $perUser = config()->integer('launchroom.commands.max_open_per_user');

            if (CommandRun::query()->where('service_instance_id', $instance->id)->whereIn('status', $open)->count() >= $perService) {
                throw DomainRuleViolation::conflict("{$perService} commands are already running for this service. Wait for one to finish or cancel it.");
            }

            if (CommandRun::query()->where('team_id', $instance->team_id)->where('user_id', $user->id)->whereIn('status', $open)->count() >= $perUser) {
                throw DomainRuleViolation::conflict("You have {$perUser} commands running. Wait for one to finish or cancel it.");
            }

            $task = $this->runOnce->oneOff($deployment, $spec, $argv, $target->server, $timeout, config()->integer('launchroom.commands.output_limit_bytes'), $target->mounts)
                ?? throw DomainRuleViolation::conflict('Commands run in the live version. Deploy this service first.');

            $run = new CommandRun([
                'team_id' => $instance->team_id,
                'project_id' => $instance->environment->project_id,
                'environment_id' => $instance->environment_id,
                'service_instance_id' => $instance->id,
                'deployment_id' => $deployment->id,
                'server_id' => $target->server->id,
                'user_id' => $user->id,
                'api_token_id' => $actor->token,
                'argv' => $argv,
                'command_name' => $name,
                'timeout_seconds' => $timeout,
            ]);
            $run->daemon_task_id = $task->id;
            $run->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::CommandStarted,
                actor: $actor,
                teamId: $instance->team_id,
                subjectType: 'service',
                subjectId: $instance->service->public_id,
                meta: array_filter([
                    'run' => $run->public_id,
                    'environment' => $instance->environment->public_id,
                    'deployment' => $deployment->public_id,
                    'server' => $target->server->public_id,
                    'argv' => $argv,
                    'name' => $name,
                    'timeoutSeconds' => $timeout,
                    'volumes' => $target->mounts === [] ? null : count($target->mounts),
                ], static fn (mixed $value): bool => $value !== null),
            ));

            return $run;
        });

        if ($target->mounts !== []) {
            // Its NodeState declares the volumes while the task is open (NodeStateContent::releaseVolumes).
            NodeStateInvalidated::servers([$target->server->id]);
        }

        return $run;
    }

    /**
     * The argv to run and the catalogue name it came from.
     *
     * @return array{list<string>, string|null}
     */
    private function argv(ServiceInstance $instance, StartCommandRunInput $input): array
    {
        if ($input->name === null) {
            return [$input->argv ?? [], null];
        }

        $command = $this->catalogue->for($instance)->find($input->name);

        if (! $command instanceof CommandData) {
            throw DomainRuleViolation::on('name', "{$instance->service->name} has no command named \"{$input->name}\".");
        }

        return [$command->argv, $command->name];
    }
}
