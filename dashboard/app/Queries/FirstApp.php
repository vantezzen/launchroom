<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Instance\FirstAppData;
use App\Data\Instance\FirstAppStepData;
use App\Data\Services\GitSource;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentStepName;
use App\Enums\DomainKind;
use App\Enums\EnvironmentKind;
use App\Enums\Git\GitProviderKind;
use App\Enums\ServiceKind;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\Domain;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Support\Canvas\ServiceGlyph;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * The team's first app (docs/08 §3.1, flows §1 step 4): a production
 * service other than a database whose deployment went live, preferring one
 * with an address, the oldest first. Before any is live, the newest
 * unfinished production deployment, with its steps for the first-live card.
 */
final readonly class FirstApp
{
    /** The steps the card lists, with their labels (Laravel Cloud style). */
    private const array STEPS = [
        DeploymentStepName::Build->value => 'Build',
        DeploymentStepName::Rollout->value => 'Start',
        DeploymentStepName::Health->value => 'Health check',
        DeploymentStepName::Route->value => 'Route',
    ];

    /** How long after going live the canvas still celebrates it. */
    public const int CARD_MINUTES = 60;

    public function for(Team $team, bool $seen = false): ?FirstAppData
    {
        return $this->live($team, $seen) ?? $this->deploying($team, $seen);
    }

    /**
     * The first-live card on the canvas (flows §5): while the first deploy
     * runs and for an hour after it went live, until dismissed. Never for an
     * app that went live long ago (an existing install), so it stays a
     * moment rather than a banner.
     */
    public function card(Team $team, bool $seen): ?FirstAppData
    {
        if ($seen) {
            return null;
        }

        $app = $this->for($team);

        return match (true) {
            ! $app instanceof FirstAppData => null,
            $app->state === 'deploying' => $app,
            $app->liveSince?->greaterThan(now()->subMinutes(self::CARD_MINUTES)) === true => $app,
            default => null,
        };
    }

    private function live(Team $team, bool $seen): ?FirstAppData
    {
        $instance = $this->production($team)
            ->whereNotNull('service_instances.active_deployment_id')
            ->orderByRaw('exists (select 1 from domains where domains.service_instance_id = service_instances.id and domains.redirect_to is null) desc')
            ->orderBy('service_instances.active_deployment_id')
            ->with(['service', 'environment.project', 'activeDeployment'])
            ->first();

        if (! $instance instanceof ServiceInstance) {
            return null;
        }

        $domain = Domain::query()
            ->where('service_instance_id', $instance->id)
            ->whereIn('kind', [DomainKind::Custom->value, DomainKind::Generated->value])
            ->whereNull('redirect_to')
            ->orderByRaw('case when kind = ? then 0 else 1 end', [DomainKind::Custom->value])
            ->orderBy('id')
            ->value('hostname');
        $active = $instance->activeDeployment;

        return $this->data($instance, 'live', $seen,
            hostname: is_string($domain) ? $domain : null,
            liveSince: $active->finished_at ?? $active?->queued_at,
        );
    }

    private function deploying(Team $team, bool $seen): ?FirstAppData
    {
        $deployment = Deployment::query()
            ->whereIn('deployments.status', array_map(static fn (DeploymentStatus $status): string => $status->value, DeploymentStatus::unfinished()))
            ->whereIn('service_instance_id', $this->production($team)->select('service_instances.id'))
            ->orderByDesc('deployments.id')
            ->with(['serviceInstance.service', 'serviceInstance.environment.project', 'steps'])
            ->first();

        if (! $deployment instanceof Deployment) {
            return null;
        }

        $steps = [];
        $byName = $deployment->steps->keyBy(static fn (DeploymentStep $step): string => $step->name->value);

        foreach (self::STEPS as $name => $label) {
            $step = $byName->get($name);

            // Image services never build: no Build row unless a build ran.
            if ($name === DeploymentStepName::Build->value && ! $step instanceof DeploymentStep && $deployment->serviceInstance->service->kind !== ServiceKind::App) {
                continue;
            }

            $steps[] = new FirstAppStepData(
                label: $label,
                status: $step instanceof DeploymentStep ? $step->status->value : 'pending',
                startedAt: $step?->started_at,
                finishedAt: $step?->finished_at,
            );
        }

        return $this->data($deployment->serviceInstance, 'deploying', $seen,
            deploymentId: $deployment->public_id,
            startedAt: $deployment->started_at ?? $deployment->queued_at,
            steps: $steps,
        );
    }

    /**
     * @param  list<FirstAppStepData>  $steps
     */
    private function data(ServiceInstance $instance, string $state, bool $seen, ?string $hostname = null, ?CarbonInterface $liveSince = null, ?string $deploymentId = null, ?CarbonInterface $startedAt = null, array $steps = []): FirstAppData
    {
        $service = $instance->service;
        $environment = $instance->environment;
        $source = $service->source;

        return new FirstAppData(
            state: $state,
            projectId: $environment->project->public_id,
            projectName: $environment->project->name,
            environmentId: $environment->public_id,
            serviceId: $service->public_id,
            serviceName: $service->name,
            logo: ServiceGlyph::logo($service),
            hostname: $hostname,
            liveSince: $liveSince,
            deploymentId: $deploymentId,
            startedAt: $startedAt,
            steps: $steps,
            suggestGit: $source instanceof GitSource && $source->provider === GitProviderKind::Git,
            seen: $seen,
        );
    }

    /**
     * Current production instances of the team's services that aren't databases.
     *
     * @return EloquentBuilder<ServiceInstance>
     */
    private function production(Team $team): EloquentBuilder
    {
        return ServiceInstance::query()
            ->select('service_instances.*')
            ->whereNull('service_instances.removed_at')
            ->whereHas('environment', static fn (Builder $environment) => $environment
                ->where('kind', EnvironmentKind::Production->value)
                ->whereHas('project', static fn (Builder $project) => $project->where('team_id', $team->id)))
            ->whereHas('service', static fn (Builder $service) => $service->where('kind', '!=', ServiceKind::Database->value));
    }
}
