<?php

declare(strict_types=1);

namespace App\Actions\Canvas;

use App\Actions\Connections\CreateConnection;
use App\Actions\Services\CreateService;
use App\Actions\Services\UpdateServiceSettings;
use App\Contracts\Git\SourceRepository;
use App\Data\Audit\AuditActor;
use App\Data\Canvas\AddServiceData;
use App\Data\Canvas\CanvasPositionData;
use App\Data\Services\CreateServiceData;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\ServiceKind;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Git\EnsureRepositoryWebhookJob;
use App\Models\CanvasLayout;
use App\Models\Environment;
use App\Models\Git\GitRepository;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Canvas\ConnectionPreview;
use App\Services\Git\GitProviders;
use Illuminate\Support\Facades\DB;

/**
 * "Add service" from the canvas: resolves the picked repository within the
 * project's team, creates the service in this environment only (staged,
 * docs/06 §4; other environments get it through Compare & promote), pins its branch
 * in this environment when it differs from the environment's branch, and
 * places the node where the user dropped it. A new database can be connected
 * to services right away ("Connect it to…", `connectTo`), with the same
 * defaults as the Connect popover.
 */
final readonly class AddService
{
    public function __construct(
        private CreateService $createService,
        private UpdateServiceSettings $updateSettings,
        private CreateConnection $createConnection,
        private ConnectionPreview $connectionPreview,
        private GitProviders $providers,
    ) {}

    public function handle(Project $project, Environment $environment, AddServiceData $data, User $user, AuditActor $actor): Service
    {
        return DB::transaction(function () use ($project, $environment, $data, $user, $actor): Service {
            $service = $this->createService->handle($project, CreateServiceData::from([
                'name' => $data->name,
                'kind' => $data->kind,
                'source' => $this->source($project, $data),
                'parentServiceId' => $data->parentServiceId,
                'port' => $data->kind->isWeb() ? $data->port : null,
                'startCommand' => $data->startCommand,
            ]), $user, $actor, $environment);

            $instance = ServiceInstance::query()
                ->where('service_id', $service->id)
                ->where('environment_id', $environment->id)
                ->first();

            if ($instance instanceof ServiceInstance && $data->kind === ServiceKind::App && $data->branch !== null && $data->branch !== $environment->triggers->branch) {
                $this->updateSettings->handle($instance, UpdateServiceSettingsData::from(['branchOverride' => $data->branch]));
            }

            if ($data->position instanceof CanvasPositionData) {
                $layout = CanvasLayout::query()->firstOrCreate(['environment_id' => $environment->id]);
                $layout->positions = [...$layout->positions, $service->public_id => ['x' => $data->position->x, 'y' => $data->position->y]];
                $layout->save();
            }

            foreach ($data->kind === ServiceKind::Database ? $data->connectTo ?? [] : [] as $consumerId) {
                $consumer = $project->services()->where('public_id', $consumerId)->with('parent')->first();

                if (! $consumer instanceof Service) {
                    throw DomainRuleViolation::on('connectTo', 'Pick services of this project.');
                }

                $defaults = $this->connectionPreview->preview($consumer, $service, null, null);

                if ($defaults->error !== null) {
                    throw DomainRuleViolation::on('connectTo', $defaults->error);
                }

                $this->createConnection->handle($consumer, $service, $defaults->mode, $defaults->style, $user, $actor);
            }

            return $service;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function source(Project $project, AddServiceData $data): ?array
    {
        return match ($data->kind) {
            ServiceKind::App => $this->gitSource($project, $data),
            ServiceKind::Image => ['type' => 'image', 'reference' => (string) $data->image],
            ServiceKind::Database => ['type' => 'database', 'engine' => $data->engine?->value, 'version' => $data->version],
            ServiceKind::Worker, ServiceKind::Cron => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function gitSource(Project $project, AddServiceData $data): array
    {
        $team = $project->team;
        $repository = $this->providers->repository($team, (string) $data->repository);

        if (! $repository instanceof SourceRepository) {
            throw DomainRuleViolation::on('repository', 'Pick a repository one of the team’s git connections can access.');
        }

        // GitLab/Gitea: register launchroom's webhook once the service exists.
        if ($repository instanceof GitRepository && $repository->gitConnection->provider->hasApi() && $repository->hook_id === null) {
            dispatch(new EnsureRepositoryWebhookJob($repository->id, $repository->team_id))->afterCommit();
        }

        return $repository->gitSource($data->rootDirectory ?? '');
    }
}
