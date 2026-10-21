<?php

declare(strict_types=1);

namespace App\Queries\Api;

use App\Data\Api\ApiCommitData;
use App\Data\Api\ApiFailureData;
use App\Data\Api\DeploymentResource;
use App\Data\Api\DomainResource;
use App\Data\Api\EnvironmentRefData;
use App\Data\Api\EnvironmentResource;
use App\Data\Api\ProjectResource;
use App\Data\Api\ServerResource;
use App\Data\Api\ServiceResource;
use App\Data\Transfers\TransferVolumeData;
use App\Enums\DeploymentStatus;
use App\Enums\DomainKind;
use App\Enums\ServerRole;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Volume;

/**
 * Maps models to the public API's resource Data (docs/16 §2.2). Callers
 * load models through team-scoped bindings or queries; this only shapes
 * them (whitelisted fields, never secrets, docs/09 R12).
 */
final class ApiResources
{
    /** Statuses after which nothing happens on its own (`finished`). */
    private const array SETTLED = [
        DeploymentStatus::Active,
        DeploymentStatus::Failed,
        DeploymentStatus::Cancelled,
        DeploymentStatus::Superseded,
        DeploymentStatus::Ready,
    ];

    public function project(Project $project): ProjectResource
    {
        $project->loadMissing('environments');

        return new ProjectResource(
            id: $project->public_id,
            name: $project->name,
            slug: $project->slug,
            createdAt: $project->created_at?->toIso8601String() ?? '',
            environments: array_values($project->environments
                ->sortBy(static fn (Environment $environment): string => $environment->kind->value === 'production' ? '0' : '1'.$environment->name)
                ->map(static fn (Environment $environment): EnvironmentRefData => new EnvironmentRefData($environment->public_id, $environment->name, $environment->slug, $environment->kind))
                ->all()),
            dashboardUrl: route('projects.show', [$project->public_id]),
        );
    }

    public function environment(Environment $environment): EnvironmentResource
    {
        $environment->loadMissing(['project', 'lastAppliedRevision']);

        return new EnvironmentResource(
            id: $environment->public_id,
            projectId: $environment->project->public_id,
            name: $environment->name,
            slug: $environment->slug,
            kind: $environment->kind,
            revision: $environment->lastAppliedRevision?->number,
            autoDeployPaused: $environment->auto_deploy_paused,
            dashboardUrl: route('projects.canvas', [$environment->project->public_id, $environment->public_id]),
        );
    }

    public function service(ServiceInstance $instance): ServiceResource
    {
        $instance->loadMissing(['service', 'environment.project', 'activeDeployment', 'domains', 'volumes']);

        $latest = Deployment::query()
            ->where('service_instance_id', $instance->id)
            ->with(['serviceInstance.service', 'environment.project'])
            ->latest('id')
            ->first();
        $active = $instance->activeDeployment;
        $active?->setRelation('serviceInstance', $instance)->setRelation('environment', $instance->environment);

        $status = match (true) {
            $latest !== null && ! $this->finished($latest->status) => $latest->status->value,
            $active !== null => 'live',
            $latest !== null => $latest->status->value,
            default => 'not_deployed',
        };

        $urls = array_values($instance->domains
            ->filter(static fn (Domain $domain): bool => $domain->kind !== DomainKind::Deployment && $domain->redirect_to === null)
            ->sortBy(static fn (Domain $domain): string => ($domain->kind === DomainKind::Custom ? '0' : '1').$domain->hostname)
            ->map(static fn (Domain $domain): string => 'https://'.$domain->hostname)
            ->all());

        $environment = $instance->environment;

        return new ServiceResource(
            id: $instance->service->public_id,
            environmentId: $environment->public_id,
            name: $instance->service->name,
            slug: $instance->service->slug,
            kind: $instance->service->kind,
            status: $status,
            activeDeployment: $active === null ? null : $this->deployment($active),
            latestDeployment: $latest === null ? null : $this->deployment($latest),
            urls: $urls,
            dashboardUrl: route('projects.services.show', [$environment->project->public_id, $environment->public_id, $instance->service->public_id]),
            volumes: array_values($instance->volumes->sortBy('mount_path')->map(static fn (Volume $volume): TransferVolumeData => new TransferVolumeData($volume->public_id, $volume->mount_path))->all()),
        );
    }

    public function deployment(Deployment $deployment): DeploymentResource
    {
        $deployment->loadMissing(['serviceInstance.service', 'environment.project']);
        $service = $deployment->serviceInstance->service;
        $environment = $deployment->environment;

        return new DeploymentResource(
            id: $deployment->public_id,
            environmentId: $environment->public_id,
            serviceId: $service->public_id,
            serviceName: $service->name,
            status: $deployment->status,
            finished: $this->finished($deployment->status),
            trigger: $deployment->trigger,
            commit: $deployment->commit_sha === null ? null : new ApiCommitData(
                sha: $deployment->commit_sha,
                shortSha: substr($deployment->commit_sha, 0, 7),
                message: $deployment->commit_message === '' ? null : $deployment->commit_message,
                author: $deployment->commit_author,
                ref: $deployment->git_ref,
            ),
            failure: $deployment->status === DeploymentStatus::Failed
                ? new ApiFailureData($deployment->failure_class, $deployment->failure_message)
                : null,
            queuedAt: $deployment->queued_at->toIso8601String(),
            startedAt: $deployment->started_at?->toIso8601String(),
            finishedAt: $deployment->finished_at?->toIso8601String(),
            dashboardUrl: route('deployments.show', [$environment->project->public_id, $environment->public_id, $deployment->public_id]),
        );
    }

    public function server(Server $server): ServerResource
    {
        return new ServerResource(
            id: $server->public_id,
            name: $server->name,
            status: $server->status,
            roles: array_values($server->roles->map(static fn (ServerRole $role): ServerRole => $role)->all()),
            isControlPlane: $server->is_control_plane,
            publicIpv4: $server->public_ipv4,
            publicIpv6: $server->public_ipv6,
            meshIp: $server->mesh_ip,
            daemonVersion: $server->daemon_version,
            lastSeenAt: $server->last_seen_at?->toIso8601String(),
            labels: $server->labels,
        );
    }

    public function domain(Domain $domain): DomainResource
    {
        return new DomainResource(
            id: $domain->public_id,
            hostname: $domain->hostname,
            kind: $domain->kind,
            redirectTo: $domain->redirect_to,
            certStatus: $domain->cert_status,
            certExpiresAt: $domain->cert_expires_at?->toIso8601String(),
            hsts: $domain->hsts,
        );
    }

    public function finished(DeploymentStatus $status): bool
    {
        return in_array($status, self::SETTLED, true);
    }
}
