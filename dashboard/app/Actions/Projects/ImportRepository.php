<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Canvas\AddService;
use App\Actions\Connections\CreateConnection;
use App\Contracts\Git\SourceRepository;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Canvas\AddServiceData;
use App\Data\Environments\EnvironmentTriggers;
use App\Data\Projects\CreateProjectData;
use App\Data\Projects\ImportAppData;
use App\Data\Projects\ImportDatabaseData;
use App\Data\Projects\ImportRepositoryData;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\AuditAction;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Enums\ServiceKind;
use App\Exceptions\DomainRuleViolation;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Services\Git\GitProviders;
use App\Support\Git\GitRef;
use Illuminate\Support\Facades\DB;

/**
 * "Import a repository" (flows.md §3) in one transaction: the project (named
 * by the user, production tracking the picked branch), one service per
 * picked app (monorepo apps with their root directory), the picked databases
 * and a connection from every app that uses one (framework-aware variable
 * names). Deploying is the caller's next step: a brand-new project deploys
 * directly, without a staged review (maintainer decision 2026-10-19).
 */
final readonly class ImportRepository
{
    public function __construct(
        private CreateProject $createProject,
        private AddService $addService,
        private CreateConnection $createConnection,
        private DatabaseEngines $engines,
        private RecordAuditEvent $audit,
        private GitProviders $providers,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public function handle(Team $team, ImportRepositoryData $data, User $user, AuditActor $actor): Project
    {
        $repository = $this->providers->repository($team, $data->repository);

        if (! $repository instanceof SourceRepository) {
            throw DomainRuleViolation::on('repository', 'Pick a repository one of the team’s git connections can access.');
        }

        if (! GitRef::isValid('refs/heads/'.$data->branch)) {
            throw DomainRuleViolation::on('branch', 'Enter a valid branch name.');
        }

        $roots = array_map(static fn (ImportAppData $app): string => trim((string) $app->rootDirectory, '/'), $data->apps);

        if (count(array_unique($roots)) !== count($roots)) {
            throw DomainRuleViolation::on('apps', 'Each app needs its own folder.');
        }

        return DB::transaction(function () use ($team, $data, $user, $actor, $repository, $roots): Project {
            $project = $this->createProject->handle($team, new CreateProjectData($data->name), $actor);
            $production = $project->production()->sole();
            $production->forceFill(['triggers' => new EnvironmentTriggers(branch: $data->branch)])->save();

            /** @var array<string, array{service: Service, framework: string|null}> $apps root directory => service */
            $apps = [];

            foreach ($data->apps as $index => $app) {
                $service = $this->add($project, $production, $user, $actor, [
                    'name' => $app->name,
                    'kind' => ServiceKind::App,
                    'repository' => $repository->repositoryPublicId(),
                    'branch' => $data->branch,
                    'rootDirectory' => $roots[$index] === '' ? null : $roots[$index],
                    'port' => $app->port,
                ], 'apps.'.$index);
                $apps[$roots[$index]] = [
                    'service' => $service,
                    'framework' => $app->framework === null ? null : strtolower($app->framework),
                ];

                // Suggested processes the user kept: workers from the app's image.
                foreach ($app->processes as $p => $process) {
                    $this->add($project, $production, $user, $actor, [
                        'name' => $process->name,
                        'kind' => ServiceKind::Worker,
                        'parentServiceId' => $service->public_id,
                        'startCommand' => $process->command,
                    ], 'apps.'.$index.'.processes.'.$p);
                }
            }

            foreach ($data->databases as $index => $database) {
                $driver = $this->engines->for($database->engine);
                $service = $this->add($project, $production, $user, $actor, [
                    'name' => $database->name,
                    'kind' => ServiceKind::Database,
                    'engine' => $database->engine,
                    'version' => $driver->defaultVersion(),
                ], 'databases.'.$index);

                foreach (array_unique($database->connectTo) as $root) {
                    $app = $apps[trim($root ?? '', '/')] ?? null;

                    if ($app === null) {
                        continue;
                    }

                    $style = $app['framework'] === 'laravel' ? ConnectionStyle::Laravel : ConnectionStyle::Url;
                    $this->createConnection->handle($app['service'], $service, ConnectionMode::Private, $style, $user, $actor);
                }
            }

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ProjectImported,
                actor: $actor,
                teamId: $team->id,
                subjectType: 'project',
                subjectId: $project->public_id,
                meta: [
                    'repository' => $repository->repositoryFullName(),
                    'provider' => $repository->repositoryRef()->provider->value,
                    'branch' => $data->branch,
                    'apps' => count($data->apps),
                    'processes' => array_sum(array_map(static fn (ImportAppData $app): int => count($app->processes), $data->apps)),
                    'databases' => array_map(static fn (ImportDatabaseData $database): string => $database->engine->value, $data->databases),
                ],
            ));

            return $project;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws DomainRuleViolation
     */
    private function add(Project $project, Environment $environment, User $user, AuditActor $actor, array $input, string $field): Service
    {
        try {
            return $this->addService->handle($project, $environment, AddServiceData::from([
                'repository' => null, 'branch' => null, 'rootDirectory' => null, 'image' => null, 'port' => null,
                'engine' => null, 'version' => null, 'parentServiceId' => null, 'startCommand' => null, 'position' => null,
                ...$input,
            ]), $user, $actor);
        } catch (DomainRuleViolation $e) {
            // Point the message at the app or database it came from.
            throw DomainRuleViolation::on($field.'.name', $e->getMessage());
        }
    }
}
