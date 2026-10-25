<?php

declare(strict_types=1);

namespace App\Actions\Environments;

use App\Actions\Audit\RecordAuditEvent;
use App\Casts\ServiceSourceCast;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Services\BuildSettings;
use App\Data\Services\DeploySettings;
use App\Data\Services\ScalingSettings;
use App\Data\Specs\ChangeItem;
use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;
use App\Data\Specs\VariableSpec;
use App\Enums\AuditAction;
use App\Enums\Backups\DeletionRequestStatus;
use App\Enums\ChangeKind;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\DeletionRequest;
use App\Models\Connection;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\EnvironmentRevision;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Variable;
use App\Models\VariableTarget;
use App\Models\Volume;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\Variables\VariableStore;
use App\Support\Changes\SpecDiff;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Data;

/**
 * Discard staged changes (docs/06 §4): restores the touched items from the
 * last applied revision, all of them or only those under the given paths
 * (`services.svc_…`, `services.svc_….deploy.startCommand`, …).
 *
 * With `$revision` it is "Revert to revision N" (docs/06 §6.5): the intent of
 * that older revision comes back as staged changes (values as new versions).
 *
 * Variables are restored with their recorded id, version and ciphertext, so
 * the associated data still matches. Restoring a removed variable targets it
 * at this environment (the revision records how it applied here, not every
 * target it had). Service identity fields (name, icon, source) are shared by
 * all environments; restoring them affects every environment.
 */
final class DiscardChanges
{
    /** Revert: restored values get a new version instead of their recorded one. */
    private bool $asNewVersions = false;

    public function __construct(
        private readonly CurrentEnvironmentSpec $currentSpec,
        private readonly VariableStore $variables,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  list<string>|null  $paths  null: everything
     * @return int number of discarded items
     */
    public function handle(Environment $environment, ?array $paths, AuditActor $actor, ?EnvironmentRevision $revision = null): int
    {
        return DB::transaction(function () use ($environment, $paths, $actor, $revision): int {
            $environment = Environment::query()->whereKey($environment->id)->with(['project', 'lastAppliedRevision'])->lockForUpdate()->firstOrFail();

            if ($revision instanceof EnvironmentRevision && $revision->environment_id !== $environment->id) {
                throw DomainRuleViolation::on('revision', 'That revision belongs to another environment.');
            }

            $base = $revision instanceof EnvironmentRevision ? $revision->spec : $environment->lastAppliedRevision?->spec;

            if ($base === null) {
                throw DomainRuleViolation::on('environment', 'This environment has never been deployed, so there is nothing to go back to.');
            }

            $this->asNewVersions = $revision instanceof EnvironmentRevision;
            $changes = SpecDiff::between($base, $this->currentSpec->get($environment));
            $items = $paths === null ? $changes->items : $changes->itemsUnder($paths);

            foreach ($items as $item) {
                $this->discard($environment, $base, $item);
            }

            if ($items !== []) {
                $this->audit->handle(new AuditEntryData(
                    action: $revision instanceof EnvironmentRevision ? AuditAction::EnvironmentReverted : AuditAction::ChangesDiscarded,
                    actor: $actor,
                    teamId: $environment->team_id,
                    subjectType: 'environment',
                    subjectId: $environment->public_id,
                    meta: [
                        'paths' => array_map(static fn (ChangeItem $item): string => $item->path, $items),
                        ...($revision instanceof EnvironmentRevision ? ['revision' => $revision->number] : []),
                    ],
                ));
            }

            return count($items);
        });
    }

    private function discard(Environment $environment, EnvironmentSpec $base, ChangeItem $item): void
    {
        $segments = explode('.', $item->path);

        match ($segments[0]) {
            'environment' => $this->restoreEnvironment($environment, $base),
            'connections' => $this->discardConnection($environment, $base, $segments[1], $item->kind),
            'sharedVariables' => $this->discardVariable($environment, null, $base->sharedVariables[$segments[1]] ?? null, $segments[1], $item->kind),
            'services' => $this->discardService($environment, $base, $segments, $item->kind),
            default => null,
        };
    }

    private function restoreEnvironment(Environment $environment, EnvironmentSpec $base): void
    {
        $environment->forceFill(['name' => $base->environment->name, 'triggers' => $base->environment->triggers])->save();
    }

    /**
     * @param  list<string>  $segments
     */
    private function discardService(Environment $environment, EnvironmentSpec $base, array $segments, ChangeKind $kind): void
    {
        $serviceId = $segments[1];
        $spec = $base->service($serviceId);
        $service = Service::query()->where('project_id', $environment->project_id)->where('public_id', $serviceId)->first();
        $instance = $service === null ? null : ServiceInstance::query()->where('environment_id', $environment->id)->where('service_id', $service->id)->first();

        if (count($segments) === 2) {
            if ($kind === ChangeKind::Added && $instance !== null) {
                $instance->delete();

                if (! $service->instances()->exists()) {
                    $service->delete();
                }
            } elseif ($kind === ChangeKind::Removed && $instance !== null) {
                $instance->forceFill(['removed_at' => null])->save();
                // The removal's final backup no longer gates anything (docs/14 §9.13).
                $pending = DeletionRequest::openFor(DeletionRequest::SERVICE_INSTANCE, $instance->id, lock: true);
                $pending?->transitionTo(DeletionRequestStatus::Cancelled);
            }

            return;
        }

        if (! $spec instanceof ServiceSpec || $service === null || $instance === null) {
            return;
        }

        $field = $segments[2];

        match ($field) {
            'name', 'icon' => $service->forceFill([$field => $field === 'name' ? $spec->name : $spec->icon])->save(),
            'source' => $service->forceFill(['source' => ServiceSourceCast::fromArray($spec->source)])->save(),
            'parentId' => $service->forceFill(['parent_service_id' => $spec->parentId === null ? null : Service::query()->where('public_id', $spec->parentId)->value('id')])->save(),
            'enabled' => $instance->forceFill(['enabled' => $spec->enabled])->save(),
            'branchOverride' => $instance->forceFill(['branch_override' => $spec->branchOverride])->save(),
            'build', 'deploy', 'scaling' => $this->restoreSetting($instance, $spec, $field, $segments[3] ?? null),
            'variables', 'connectionVariables' => $this->discardVariable(
                $environment,
                $service,
                ($field === 'variables' ? $spec->variables : $spec->connectionVariables)[$segments[3]] ?? null,
                $segments[3],
                $kind,
            ),
            'sharedVariables' => $this->discardUsage($service, $segments[3], $kind),
            // Hostnames contain dots: the rest of the path is the key.
            'domains' => $this->discardDomain($environment, $base, $instance, $spec, implode('.', array_slice($segments, 3)), $kind),
            'volumes' => $this->discardVolume($instance, $spec, $segments[3], $kind),
            default => null,
        };
    }

    private function restoreSetting(ServiceInstance $instance, ServiceSpec $spec, string $group, ?string $property): void
    {
        $current = $instance->{$group};
        $recorded = $spec->{$group};

        if (! $current instanceof Data || ! $recorded instanceof Data) {
            return;
        }

        $values = $current->toArray();
        $values = $property === null ? $recorded->toArray() : [...$values, $property => $recorded->toArray()[$property] ?? null];

        $instance->forceFill([$group => match ($group) {
            'build' => BuildSettings::from($values),
            'deploy' => DeploySettings::from($values),
            default => ScalingSettings::from($values),
        }])->save();
    }

    private function discardVariable(Environment $environment, ?Service $service, ?VariableSpec $spec, string $variableId, ChangeKind $kind): void
    {
        $variable = Variable::query()->where('project_id', $environment->project_id)->where('public_id', $variableId)->first();

        if ($kind === ChangeKind::Added) {
            $variable?->delete();

            return;
        }

        if (! $spec instanceof VariableSpec) {
            return;
        }

        // A secret withheld from a fork preview (docs/09 R20) is never
        // recreated as a row of that preview, which would hand it the value.
        if ($spec->withheld && $variable === null) {
            return;
        }

        if ($variable !== null) {
            $this->asNewVersions ? $this->variables->restoreAsNewVersion($environment->project, $variable, $spec) : $this->variables->restore($variable, $spec);

            return;
        }

        $connection = $spec->connectionId === null ? null : Connection::query()->where('public_id', $spec->connectionId)->where('project_id', $environment->project_id)->first();

        if ($spec->connectionId !== null && $connection === null) {
            // Restored together with its connection.
            return;
        }

        $variable = new Variable;
        $variable->forceFill([
            'public_id' => $spec->id,
            'team_id' => $environment->team_id,
            'project_id' => $environment->project_id,
            'service_id' => $service?->id,
            'connection_id' => $connection?->id,
        ]);
        $this->asNewVersions ? $this->variables->restoreAsNewVersion($environment->project, $variable, $spec) : $this->variables->restore($variable, $spec);

        if ($connection === null) {
            VariableTarget::query()->create([
                'variable_id' => $variable->id,
                'environment_id' => $spec->layer === VariableSpec::LAYER_BRANCH ? null : $environment->id,
                'all_previews' => $spec->layer === VariableSpec::LAYER_BRANCH,
            ]);
        }
    }

    private function discardUsage(Service $service, string $variableId, ChangeKind $kind): void
    {
        $variable = Variable::query()->where('project_id', $service->project_id)->whereNull('service_id')->where('public_id', $variableId)->first();

        if ($variable === null) {
            return;
        }

        $kind === ChangeKind::Added ? $variable->usedBy()->detach($service->id) : $variable->usedBy()->syncWithoutDetaching([$service->id]);
    }

    private function discardDomain(Environment $environment, EnvironmentSpec $base, ServiceInstance $instance, ServiceSpec $spec, string $hostname, ChangeKind $kind): void
    {
        $domain = Domain::query()->where('service_instance_id', $instance->id)->where('hostname', $hostname)->first();
        $recorded = $spec->domains[$hostname] ?? null;

        if ($kind === ChangeKind::Added) {
            // Moved here from another service of this environment (a swap
            // after a restore, docs/14 §9.16): it goes back instead.
            if ($domain === null) {
                return;
            }

            $owner = $this->recordedOwner($environment, $base, $hostname, $instance);
            $owner instanceof ServiceInstance ? $domain->forceFill(['service_instance_id' => $owner->id])->save() : $domain->delete();

            return;
        }

        if ($recorded === null) {
            return;
        }

        if ($domain === null) {
            $elsewhere = Domain::query()->where('hostname', $hostname)->first();

            if ($elsewhere !== null) {
                $here = ServiceInstance::query()->whereKey($elsewhere->service_instance_id)->where('environment_id', $environment->id)->exists();

                if (! $here) {
                    throw DomainRuleViolation::conflict("{$hostname} is now used by another service, so it can't be restored.");
                }

                $elsewhere->forceFill(['service_instance_id' => $instance->id, 'kind' => $recorded->kind, 'redirect_to' => $recorded->redirectTo])->save();

                return;
            }

            $domain = new Domain;
            $domain->forceFill(['public_id' => $recorded->id, 'team_id' => $instance->team_id, 'service_instance_id' => $instance->id, 'hostname' => $hostname]);
        }

        $domain->forceFill(['kind' => $recorded->kind, 'redirect_to' => $recorded->redirectTo])->save();
    }

    /**
     * The other instance of this environment whose recorded spec has the hostname.
     */
    private function recordedOwner(Environment $environment, EnvironmentSpec $base, string $hostname, ServiceInstance $except): ?ServiceInstance
    {
        foreach ($base->services as $serviceSpec) {
            if ($serviceSpec->instanceId !== $except->public_id && isset($serviceSpec->domains[$hostname])) {
                return ServiceInstance::query()->where('environment_id', $environment->id)->where('public_id', $serviceSpec->instanceId)->first();
            }
        }

        return null;
    }

    private function discardVolume(ServiceInstance $instance, ServiceSpec $spec, string $volumeId, ChangeKind $kind): void
    {
        $volume = Volume::query()->where('service_instance_id', $instance->id)->where('public_id', $volumeId)->first();
        $recorded = $spec->volumes[$volumeId] ?? null;

        if ($kind === ChangeKind::Added) {
            // Never delete a volume that may hold data.
            if ($volume !== null && $volume->server_id === null) {
                $volume->delete();
            }

            return;
        }

        if ($recorded === null) {
            return;
        }

        if ($volume === null) {
            $volume = new Volume;
            $volume->forceFill(['public_id' => $recorded->id, 'team_id' => $instance->team_id, 'service_instance_id' => $instance->id]);
        }

        // A staged removal (docs/14 §9.12) comes back with its files.
        $volume->forceFill(['mount_path' => $recorded->mountPath, 'removed_at' => null])->save();
    }

    private function discardConnection(Environment $environment, EnvironmentSpec $base, string $connectionId, ChangeKind $kind): void
    {
        $connection = Connection::query()->where('project_id', $environment->project_id)->where('public_id', $connectionId)->first();

        if ($kind === ChangeKind::Added) {
            if ($connection !== null) {
                $connection->variables()->delete();
                $connection->delete();
            }

            return;
        }

        $recorded = $base->connections[$connectionId] ?? null;

        if ($recorded === null) {
            return;
        }

        $services = Service::query()->where('project_id', $environment->project_id)->whereIn('public_id', [$recorded->fromServiceId, $recorded->toServiceId])->pluck('id', 'public_id');

        if (! $services->has($recorded->fromServiceId) || ! $services->has($recorded->toServiceId)) {
            return;
        }

        if ($connection === null) {
            $connection = new Connection;
            $connection->forceFill([
                'public_id' => $recorded->id,
                'team_id' => $environment->team_id,
                'project_id' => $environment->project_id,
                'from_service_id' => $services[$recorded->fromServiceId],
                'to_service_id' => $services[$recorded->toServiceId],
            ]);
        }

        $owned = $base->service($recorded->fromServiceId)->connectionVariables ?? [];
        $owned = array_filter($owned, static fn (VariableSpec $variable): bool => $variable->connectionId === $recorded->id);

        $connection->forceFill(['mode' => $recorded->mode, 'style' => $recorded->style, 'injected' => array_values(array_map(static fn (VariableSpec $variable): string => $variable->key, $owned))])->save();

        $from = Service::query()->whereKey($services[$recorded->fromServiceId])->firstOrFail();

        foreach ($owned as $variable) {
            if (! Variable::query()->where('public_id', $variable->id)->exists()) {
                $this->discardVariable($environment, $from, $variable, $variable->id, ChangeKind::Removed);
            }
        }
    }
}
