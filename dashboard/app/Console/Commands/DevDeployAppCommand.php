<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Connections\CreateConnection;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Services\CreateService;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\Audit\AuditActor;
use App\Data\Projects\CreateProjectData;
use App\Data\Projects\DeleteProjectData;
use App\Data\Services\CreateServiceData;
use App\Data\Services\GitSource;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\Backups\DeletionOutcome;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\ServerStatus;
use App\Enums\ServiceKind;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Local-dev end-to-end check (docs/04 §13, docs/11 §6): a project with a
 * fixture app from the dev `git-fixtures` server (plain git source) and a
 * Postgres connected to it, deployed. The app is built on the build server,
 * pushed to the registry, rolled out, health-checked and routed; it is then
 * reachable at its generated *.localhost domain on the dev HTTP port.
 *
 * `--branch` other than the environment's trigger branch becomes the app
 * instance's branch override. Multi-server (failover test): `--replicas` and `--app-servers` /
 * `--db-servers` (server names or public ids → the services' allow-lists)
 * are staged through the regular settings action before the first deploy,
 * so the scheduler places them; `--replace` deletes an existing project of
 * the same name first (no final backup); `--json` prints machine-readable
 * ids. Refuses outside `local`.
 */
#[Signature('launchroom:dev:deploy-app {--name=hello : Project name} {--fixture=node-hello : Fixture in dev/fixtures} {--branch=main : Branch to deploy} {--without-database : Skip the Postgres service} {--git-url=http://git-fixtures:8080 : Base URL of the fixture git server} {--replicas=1 : Replicas of the app} {--app-servers= : Comma-separated servers (names or public ids) the app may run on} {--db-servers= : Comma-separated servers the database may run on} {--replace : Delete an existing project of this name first} {--json : Print ids as JSON}')]
#[Description('Local dev only: create a project with a fixture app (built from git-fixtures) and Postgres, and deploy it')]
final class DevDeployAppCommand extends Command
{
    public function handle(CreateProject $createProject, CreateService $createService, CreateConnection $connect, DeployEnvironment $deploy, UpdateServiceSettings $settings, DeleteProject $deleteProject): int
    {
        if (! app()->environment('local')) {
            $this->components->error('This command only runs in the local dev environment.');

            return self::FAILURE;
        }

        $owner = User::query()->where('is_instance_owner', true)->orderBy('id')->first();
        $team = $owner->currentTeam ?? Team::query()->orderBy('id')->first();
        $name = $this->option('name');
        $fixture = $this->option('fixture');
        $branch = $this->option('branch');
        $gitUrl = $this->option('git-url');

        if ($team === null) {
            $this->components->error('No team found. Finish /setup first.');

            return self::FAILURE;
        }

        if (! is_string($name) || ! is_string($fixture) || ! is_string($branch) || ! is_string($gitUrl) || preg_match('/^[a-z0-9-]{1,64}$/D', $fixture) !== 1) {
            return self::INVALID;
        }

        $replicas = filter_var($this->option('replicas'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 64]]);

        if ($replicas === false) {
            $this->components->error('--replicas must be between 1 and 64.');

            return self::INVALID;
        }

        $appServers = $this->servers($team, $this->option('app-servers'));
        $dbServers = $this->servers($team, $this->option('db-servers'));

        if ($appServers === null || $dbServers === null) {
            return self::INVALID;
        }

        $actor = AuditActor::system();

        if ($this->option('replace')) {
            $existing = Project::query()->where('team_id', $team->id)->where('name', $name)->first();

            if ($existing instanceof Project && $deleteProject->handle($existing, new DeleteProjectData($name, skipFinalBackup: true), $actor) !== DeletionOutcome::Deleted) {
                $this->components->error("Could not delete the existing project {$name}.");

                return self::FAILURE;
            }
        }

        $project = $createProject->handle($team, new CreateProjectData($name), $actor);

        $app = $createService->handle($project, CreateServiceData::from([
            'name' => $fixture,
            'kind' => ServiceKind::App,
            'source' => [
                'type' => 'git',
                'provider' => GitSource::PROVIDER_GIT,
                'repositoryId' => 0,
                'fullName' => 'fixtures/'.$fixture,
                'cloneUrl' => rtrim($gitUrl, '/').'/'.$fixture.'.git',
                'branch' => $branch,
            ],
        ]), $owner, $actor);

        $postgres = null;

        if (! $this->option('without-database')) {
            $postgres = $createService->handle($project, CreateServiceData::from([
                'name' => 'postgres',
                'kind' => ServiceKind::Database,
                'source' => ['type' => 'database', 'engine' => 'postgres', 'version' => '18'],
            ]), $owner, $actor);

            $connect->handle($app, $postgres, ConnectionMode::Private, ConnectionStyle::Url, $owner, $actor);
        }

        $production = $project->production()->sole();
        // The environment's trigger branch wins over the source's branch
        // (GitHeads::branchFor), so another branch is an instance override.
        $this->place($settings, $app, $production->id, $replicas, $appServers, $branch === $production->triggers->branch ? null : $branch);

        if ($postgres instanceof Service) {
            $this->place($settings, $postgres, $production->id, 1, $dbServers);
        }

        $result = $deploy->handle($production, $owner, $actor, 'dev app');
        $appInstance = $app->instances()->where('environment_id', $production->id)->sole();

        $hosts = Domain::query()
            ->whereIn('service_instance_id', $app->instances()->select('id'))
            ->get();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'project' => $project->public_id,
                'app' => $app->public_id,
                'appInstance' => $appInstance->public_id,
                'postgres' => $postgres?->public_id,
                'hostnames' => $hosts->pluck('hostname')->all(),
                'deployments' => array_map(static fn (Deployment $d): string => $d->public_id, $result->deployments),
            ], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info("Project {$project->public_id} ({$project->slug}), app {$app->public_id}, revision {$result->revision->number}.");

        foreach ($result->deployments as $deployment) {
            $this->line("  {$deployment->public_id}  {$deployment->serviceInstance->service->slug}  {$deployment->status->value}");
        }

        foreach ($hosts as $domain) {
            $this->line("  curl -H 'Host: {$domain->hostname}' http://127.0.0.1:8080/   (the dev HTTP port, LR_DEV_HTTP_PORT)");
        }

        return self::SUCCESS;
    }

    /**
     * Server names or public ids → public ids of the team's non-revoked
     * servers; null (after an error message) when one is unknown.
     *
     * @return list<string>|null
     */
    private function servers(Team $team, mixed $option): ?array
    {
        if (! is_string($option) || trim($option) === '') {
            return [];
        }

        $ids = [];

        foreach (array_filter(array_map(trim(...), explode(',', $option))) as $ref) {
            $server = Server::query()
                ->where('team_id', $team->id)
                ->where('status', '!=', ServerStatus::Revoked->value)
                ->where(static fn (Builder $q): Builder => $q->where('public_id', $ref)->orWhere('name', $ref))
                ->orderByDesc('id')
                ->first();

            if (! $server instanceof Server) {
                $this->components->error("Unknown server {$ref}.");

                return null;
            }

            $ids[] = $server->public_id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Stages replicas and the allow-list through the regular settings action
     * (validated against the team's servers like a UI edit).
     *
     * @param  list<string>  $allow
     */
    private function place(UpdateServiceSettings $settings, Service $service, int $environmentId, int $replicas, array $allow, ?string $branch = null): void
    {
        if ($replicas === 1 && $allow === [] && $branch === null) {
            return;
        }

        $instance = $service->instances()->where('environment_id', $environmentId)->sole();
        $scaling = clone $instance->scaling;
        $scaling->replicas = $replicas;
        $scaling->allowServers = $allow;

        $settings->handle($instance, $branch === null
            ? new UpdateServiceSettingsData(scaling: $scaling)
            : new UpdateServiceSettingsData(scaling: $scaling, branchOverride: $branch));
    }
}
