<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Projects\CreateProject;
use App\Actions\Services\CreateService;
use App\Data\Audit\AuditActor;
use App\Data\Projects\CreateProjectData;
use App\Data\Services\CreateServiceData;
use App\Enums\ServiceKind;
use App\Models\Deployment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Local-dev smoke test (docs/11 §6): creates a project with a Postgres
 * database in the instance owner's team and deploys it, so the dev daemon
 * (server-1) receives a NodeState with a workload. Refuses outside `local`.
 */
#[Signature('launchroom:dev:deploy-demo {--name=demo : Project name} {--engine=postgres : Database engine} {--engine-version= : Engine version (default: the driver default)}')]
#[Description('Local dev only: create a project with a database service and deploy it')]
final class DevDeployDemoCommand extends Command
{
    public function handle(CreateProject $createProject, CreateService $createService, DeployEnvironment $deploy): int
    {
        if (! app()->environment('local')) {
            $this->components->error('This command only runs in the local dev environment.');

            return self::FAILURE;
        }

        $owner = User::query()->where('is_instance_owner', true)->orderBy('id')->first();
        $team = $owner->currentTeam ?? Team::query()->orderBy('id')->first();

        if ($team === null) {
            $this->components->error('No team found. Finish /setup first.');

            return self::FAILURE;
        }

        $actor = AuditActor::system();
        $name = $this->option('name');
        $engine = $this->option('engine');
        $version = $this->option('engine-version');

        if (! is_string($name) || ! is_string($engine)) {
            return self::INVALID;
        }

        $project = $createProject->handle($team, new CreateProjectData($name), $actor);
        $service = $createService->handle($project, CreateServiceData::from([
            'name' => $engine,
            'kind' => ServiceKind::Database,
            'source' => ['type' => 'database', 'engine' => $engine, 'version' => is_string($version) ? $version : match ($engine) {
                'mysql' => '8.4',
                'redis' => '7',
                'valkey' => '8',
                // The dev VM's kernel (7.0.5) is one MongoDB 8.x refuses (SERVER-121912): dev uses 7.0.
                'mongodb' => '7.0',
                'clickhouse' => '26.8',
                default => '18',
            }],
        ]), $owner, $actor);

        $result = $deploy->handle($project->production()->sole(), $owner, $actor, 'dev demo');

        $this->components->info("Project {$project->public_id} ({$project->slug}), service {$service->public_id}, revision {$result->revision->number}.");

        foreach ($result->deployments as $deployment) {
            $deployment = Deployment::query()->whereKey($deployment->id)->firstOrFail();
            $this->line("  {$deployment->public_id}  {$deployment->status->value}  {$deployment->image}");
        }

        return self::SUCCESS;
    }
}
