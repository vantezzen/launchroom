<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Actions\Servers\FreeDiskSpace;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\Audit\AuditActor;
use App\Data\Deployments\FailureFixData;
use App\Data\Protocol\V1\ImageFacts;
use App\Data\Services\DeploySettings;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\FailureFixAction;
use App\Enums\HealthcheckType;
use App\Enums\WorkloadCapability;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Queries\CurrentEnvironmentSpec;
use App\Support\Changes\SpecDiff;
use Illuminate\Support\Facades\DB;

/**
 * The one-click fix of an explained failure (flows §6, FailureCatalogue):
 * stages the suggested setting on the failed deployment's service (port, a
 * longer health check start period, a TCP check, 4xx accepted, the
 * capabilities to switch users) and deploys it right away when it is the
 * environment's only staged change. With other changes already staged it
 * only stages, so a fix never ships someone else's work unreviewed.
 *
 * Two fixes change no setting: `fix_volume_owner` redeploys the service
 * (launchroom gives a new, empty volume to the image's numeric user on its
 * first start, NodeStateContent `initOwner`), and `free_disk` queues a
 * `cleanup` task on the server that ran out of disk (its build server or a
 * replica's server) and deploys nothing: the user deploys again once it
 * has run.
 *
 * Returns the new deployment of the service, or null when only staged (or
 * for `free_disk`).
 */
final readonly class ApplyFailureFix
{
    public function __construct(
        private UpdateServiceSettings $settings,
        private DeployEnvironment $deploy,
        private CurrentEnvironmentSpec $currentSpec,
        private RedeployService $redeploy,
        private FreeDiskSpace $freeSpace,
    ) {}

    public function handle(Deployment $deployment, FailureFixData $fix, User $user, AuditActor $actor): ?Deployment
    {
        if ($fix->action === FailureFixAction::FreeDisk) {
            $this->freeDisk($deployment, $fix, $user);

            return null;
        }

        return DB::transaction(function () use ($deployment, $fix, $user, $actor): ?Deployment {
            $environment = Environment::query()->whereKey($deployment->environment_id)->with('lastAppliedRevision')->lockForUpdate()->firstOrFail();
            $instance = ServiceInstance::query()->current()->whereKey($deployment->service_instance_id)->with('service')->first();

            if (! $instance instanceof ServiceInstance) {
                throw DomainRuleViolation::on('action', 'This service is no longer part of the environment.');
            }

            if ($fix->action === FailureFixAction::FixVolumeOwner) {
                return $this->volumeOwner($deployment, $instance, $user, $actor);
            }

            $alreadyStaged = ! SpecDiff::between($environment->lastAppliedRevision?->spec, $this->currentSpec->get($environment))->isEmpty();

            $settings = $instance->deploy->toArray();
            $healthcheck = $instance->deploy->healthcheck->toArray();
            $name = $instance->service->name;
            [$settings, $message] = match ($fix->action) {
                FailureFixAction::SetPort => [[...$settings, 'port' => $fix->port], "Use port {$fix->port} for {$name}"],
                FailureFixAction::RaiseHealthTimeout => [[...$settings, 'healthcheck' => [...$healthcheck, 'startPeriodSeconds' => $fix->seconds]], "Give {$name} {$fix->seconds}s to start"],
                FailureFixAction::UseTcpHealthcheck => [[...$settings, 'healthcheck' => [...$healthcheck, 'type' => HealthcheckType::Tcp->value]], "Check {$name} over TCP"],
                FailureFixAction::Accept4xx => [[...$settings, 'healthcheck' => [...$healthcheck, 'expectStatus' => '2xx-4xx']], "Accept 4xx answers from {$name}’s health check"],
                FailureFixAction::AddCapabilities => [[...$settings, 'capabilities' => WorkloadCapability::values()], "Let {$name} switch users"],
                FailureFixAction::AddVariable => throw DomainRuleViolation::on('action', 'Add the variable in the service’s variables.'),
                FailureFixAction::GenerateDockerfile => throw DomainRuleViolation::on('action', 'Generate the Dockerfile in the service’s build settings.'),
            };

            $this->settings->handle($instance, new UpdateServiceSettingsData(deploy: DeploySettings::from($settings)));

            if ($alreadyStaged) {
                return null;
            }

            $result = $this->deploy->handle($environment, $user, $actor, $message);

            foreach ($result->deployments as $created) {
                if ($created->service_instance_id === $instance->id) {
                    return $created;
                }
            }

            return null;
        });
    }

    /**
     * Redeploys when launchroom can give a new volume to the image's user:
     * only a numeric user that isn't root (NodeStateContent `initOwner`).
     */
    private function volumeOwner(Deployment $deployment, ServiceInstance $instance, User $user, AuditActor $actor): Deployment
    {
        $facts = $deployment->imageFacts();

        if (! $facts instanceof ImageFacts || $facts->startsAsRoot() || preg_match('/^\d{1,10}(?::\d{1,10})?$/D', (string) $facts->user) !== 1) {
            throw DomainRuleViolation::on('action', 'launchroom can only give a volume to an image that runs as a numeric user. Change the owner in the Dockerfile instead.');
        }

        return $this->redeploy->handle($instance, null, $user, $actor);
    }

    /**
     * Queues "Free space now" on the server named by the fix, if it ran this
     * deployment (its build or one of its replicas) and can do it.
     */
    private function freeDisk(Deployment $deployment, FailureFixData $fix, User $user): void
    {
        $server = $fix->server === null ? null : Server::query()
            ->where('team_id', $deployment->team_id)
            ->where('public_id', $fix->server)
            ->first();

        $ranHere = $server instanceof Server && (
            $deployment->build_server_id === $server->id
            || DaemonTask::query()->where('deployment_id', $deployment->id)->where('server_id', $server->id)->exists()
            || Placement::query()->where('deployment_id', $deployment->id)->where('server_id', $server->id)->exists()
        );

        if (! $server instanceof Server || ! $ranHere) {
            throw DomainRuleViolation::on('server', 'This deployment didn’t run on that server.');
        }

        // Feature and online checks, and an open cleanup is reused.
        $this->freeSpace->handle($server, $user);
    }
}
