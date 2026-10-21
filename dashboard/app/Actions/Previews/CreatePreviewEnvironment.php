<?php

declare(strict_types=1);

namespace App\Actions\Previews;

use App\Actions\Environments\CopyEnvironmentIntent;
use App\Data\Environments\CopyEnvironmentOptions;
use App\Data\Services\ScalingSettings;
use App\Enums\DomainKind;
use App\Enums\EnvironmentKind;
use App\Enums\PreviewStrategy;
use App\Exceptions\DomainRuleViolation;
use App\Models\CanvasLayout;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewSettings;
use App\Models\ServiceInstance;
use App\Models\Variable;
use App\Models\Volume;
use App\Services\Previews\PreviewUrls;
use App\Services\Projects\EnvironmentSubnets;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Creates a pull request's preview environment as a copy of the base
 * environment's intent (docs/06 §5, App\Actions\Environments\CopyEnvironmentIntent):
 * the same services and settings, the base's variables as rows of its own
 * (fork PRs: no secrets; never production secrets while "production secrets
 * must differ" is on), its own network, generated domains (`{svc}-pr-{n}.…`) plus a branch alias
 * (`{svc}-git-{branch}.…`, redirecting to the PR URL), 1 replica per service (resource caps), and per
 * stateful service its "In previews" strategy:
 *
 * - new_empty: a fresh instance with its own volume and credentials;
 * - clone: the same, restored from the source's latest backup before the
 *   apps start ({@see AdvancePreviews});
 * - share: no instance of its own (disabled here; references resolve to the
 *   base environment's database).
 *
 * "All previews" and branch-pattern rows apply by targeting (docs/06 §3.1);
 * fork previews additionally run with `secrets_withheld` (docs/09 R20) and
 * never share or clone a base database: every stateful service of a fork
 * preview is a new empty instance, recorded with the configured strategy it
 * replaced so the previews list and canvas can say so (docs/06 §5.3).
 * Callers own the preview row lock.
 */
final readonly class CreatePreviewEnvironment
{
    public function __construct(
        private EnvironmentSubnets $subnets,
        private CopyEnvironmentIntent $copyIntent,
        private PreviewUrls $urls,
    ) {}

    public function handle(Preview $preview, Environment $base): Environment
    {
        return DB::transaction(function () use ($preview, $base): Environment {
            $project = $preview->project;

            if ($base->project_id !== $project->id || $base->kind === EnvironmentKind::Preview) {
                throw DomainRuleViolation::on('base', 'The base environment must be a non-preview environment of this project.');
            }

            // A leftover environment with this slug (e.g. a teardown that
            // failed half-way) is replaced.
            $slug = $preview->slug();

            $environment = Environment::query()->create([
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'name' => 'PR #'.$preview->pr_number,
                'slug' => $project->environments()->where('slug', $slug)->exists() ? $slug.'-'.strtolower(substr($preview->public_id, -4)) : $slug,
                'kind' => EnvironmentKind::Preview,
                'base_environment_id' => $base->id,
                'pr_number' => $preview->pr_number,
                'pr_head_sha' => $preview->head_sha,
                'pr_head_ref' => $preview->head_ref,
                'secrets_withheld' => $preview->is_fork,
                'network_subnet' => $this->subnets->allocate(),
            ]);
            $environment->setRelation('project', $project);

            CanvasLayout::query()->create(['environment_id' => $environment->id]);

            // The base's services, settings, positions and variables (fork
            // PRs: no secrets), recorded as the base for Compare & promote.
            $this->copyIntent->handle($base, $environment, new CopyEnvironmentOptions(secrets: ! $preview->is_fork));

            $templates = ServiceInstance::query()
                ->current()
                ->where('environment_id', $base->id)
                ->with('service')
                ->orderBy('id')
                ->get();

            $downgraded = [];

            foreach ($templates as $template) {
                $instance = ServiceInstance::query()->where('environment_id', $environment->id)->where('service_id', $template->service_id)->with('service')->first();

                if (! $instance instanceof ServiceInstance) {
                    continue;
                }

                $scaling = $instance->scaling;

                $instance->forceFill([
                    // Resource caps (docs/06 §5): one replica per service and
                    // at most `previews.max_cpus` CPU; memory and placement
                    // constraints as the base (an app needing 1 GB still gets
                    // it). Only servers allowing previews host them (scheduler).
                    'scaling' => new ScalingSettings(
                        min(1, $scaling->replicas),
                        min($scaling->cpus, config()->float('launchroom.previews.max_cpus')),
                        $scaling->memoryMb,
                        $scaling->placementLabels,
                        $scaling->strategy,
                        $scaling->allowServers,
                        $scaling->denyServers,
                    ),
                    'preview_clone_source_id' => $template->preview_clone_source_id,
                    'preview_sanitize_command' => $template->preview_sanitize_command,
                ])->save();

                if ($this->downgrade($preview, $base, $template)) {
                    $downgraded[$template->service->public_id] = ['strategy' => PreviewStrategy::NewEmpty->value, 'downgradedFrom' => $template->preview_strategy?->value, 'downgradeReason' => $preview->is_fork ? 'fork' : 'production'];
                    $instance->forceFill([
                        'preview_strategy' => PreviewStrategy::NewEmpty,
                        'preview_clone_source_id' => null,
                        'preview_sanitize_command' => null,
                    ])->save();
                } elseif ($template->service->kind->isStateful() && $template->preview_strategy === PreviewStrategy::Share) {
                    $this->share($instance);

                    continue;
                }

                $this->addBranchAlias($instance, $preview);
            }

            $data = $preview->data;
            $preview->forceFill([
                'environment_id' => $environment->id,
                'data' => [...$data, 'databases' => [...(is_array($data['databases'] ?? null) ? $data['databases'] : []), ...$downgraded]],
            ])->save();

            return $environment;
        });
    }

    /**
     * Whether a database's "share"/"clone" setting becomes "new empty" for
     * this preview: always for forks (fork code never gets the base's data,
     * credentials or network, docs/09 R20), and "share" of a production
     * database once "Allow production data in previews" is off.
     */
    private function downgrade(Preview $preview, Environment $base, ServiceInstance $template): bool
    {
        if (! $template->service->kind->isStateful()) {
            return false;
        }

        return match ($template->preview_strategy) {
            PreviewStrategy::Clone => $preview->is_fork,
            PreviewStrategy::Share => $preview->is_fork || ($base->kind === EnvironmentKind::Production && ! PreviewSettings::forProject($base->project)->allow_production_clone),
            default => false,
        };
    }

    /**
     * "Share base instance": nothing of its own runs in the preview.
     */
    private function share(ServiceInstance $instance): void
    {
        $instance->forceFill(['enabled' => false])->save();
        Volume::query()->where('service_instance_id', $instance->id)->delete();

        // The generated credentials created for this environment only.
        Variable::query()
            ->where('service_id', $instance->service_id)
            ->whereHas('targets', static fn (Builder $query) => $query->where('environment_id', $instance->environment_id))
            ->whereDoesntHave('targets', static fn (Builder $query) => $query->where(static fn (Builder $other) => $other->whereNull('environment_id')->orWhere('environment_id', '!=', $instance->environment_id)))
            ->delete();
    }

    private function addBranchAlias(ServiceInstance $instance, Preview $preview): void
    {
        $canonical = Domain::query()->where('service_instance_id', $instance->id)->where('kind', DomainKind::Generated->value)->orderBy('id')->value('hostname');

        if (! is_string($canonical)) {
            return;
        }

        $hostname = $this->urls->branchHostname($instance->service, $preview->head_ref, $preview->project);

        if ($hostname === null || Domain::query()->where('hostname', $hostname)->exists()) {
            return;
        }

        Domain::query()->create([
            'team_id' => $instance->team_id,
            'service_instance_id' => $instance->id,
            'hostname' => $hostname,
            'kind' => DomainKind::Generated,
            // The alias redirects to the PR's URL (one route, www-style).
            'redirect_to' => $canonical,
        ]);
    }
}
