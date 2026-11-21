<?php

declare(strict_types=1);

namespace App\Listeners\Alerts;

use App\Actions\Alerts\RaiseAlert;
use App\Actions\Alerts\ResolveAlert;
use App\Data\Alerts\AlertSignal;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\AppUpdates\AppUpdateStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\EnvironmentKind;
use App\Events\DeploymentStatusChanged;
use App\Models\AppUpdates\AppUpdate;
use App\Models\Deployment;
use App\Support\Links;
use Throwable;

/**
 * `deploy_failed` (docs/15 §3.4): a failed deployment of a lasting
 * environment raises an alert for its service; the service's next live
 * deployment resolves it. Previews never alert. A listener never changes
 * the deployment's outcome, so failures are reported, not thrown.
 *
 * An automatic rollback (probation, OD1) going live raises it too: the new
 * version failed after it went live, and auto-deploy stays paused until
 * someone resumes it. The next live deployment that isn't such a rollback
 * resolves it.
 */
final readonly class RaiseDeployFailedAlerts
{
    public function __construct(
        private RaiseAlert $raise,
        private ResolveAlert $resolve,
    ) {}

    public function handle(DeploymentStatusChanged $event): void
    {
        if (! in_array($event->to, [DeploymentStatus::Failed, DeploymentStatus::Active], true)) {
            return;
        }

        try {
            $this->apply($event);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function apply(DeploymentStatusChanged $event): void
    {
        $deployment = Deployment::query()
            ->whereKey($event->deploymentId)
            ->with(['team', 'serviceInstance.service', 'serviceInstance.activeDeployment', 'environment.project'])
            ->first();

        if (! $deployment instanceof Deployment || $deployment->environment->kind === EnvironmentKind::Preview) {
            return;
        }

        $instance = $deployment->serviceInstance;
        $environment = $deployment->environment;
        $subject = 'service:'.$instance->public_id;
        $sha = $deployment->commit_sha !== null ? substr($deployment->commit_sha, 0, 7) : null;

        if ($event->to === DeploymentStatus::Active && is_string($deployment->rollback_reason) && $deployment->rollback_reason !== '') {
            $update = $this->updateBefore($deployment);

            $this->raise->handle(new AlertSignal(
                team: $deployment->team,
                rule: AlertRule::DeployFailed,
                subject: $subject,
                title: $update instanceof AppUpdate
                    ? "{$instance->service->name}’s update to {$update->to_version} was rolled back in {$environment->name}"
                    : "{$instance->service->name} was rolled back in {$environment->name}",
                summary: mb_substr(rtrim($deployment->rollback_reason).' New pushes don’t go live until you resume deploys.', 0, 1000),
                severity: $environment->kind === EnvironmentKind::Production ? AlertSeverity::Critical : AlertSeverity::Warning,
                href: Links::deployment($deployment),
                project: $environment->project,
                environment: $environment,
                serviceInstance: $instance,
                facts: ['Now live' => $sha ?? $deployment->public_id],
                occurrence: $deployment->public_id,
            ));

            return;
        }

        if ($event->to === DeploymentStatus::Active) {
            $this->resolve->handle($deployment->team_id, AlertRule::DeployFailed, $subject, ($sha !== null ? "{$sha} is live" : 'A new deployment is live').' in '.$environment->name.'.');

            return;
        }

        $serving = $instance->activeDeployment;
        $reason = is_string($deployment->failure_message) && $deployment->failure_message !== ''
            ? rtrim(mb_substr(strtok($deployment->failure_message, "\n") ?: $deployment->failure_message, 0, 300), '.').'.'
            : 'The deployment did not finish.';
        $reassurance = $serving instanceof Deployment && $serving->id !== $deployment->id
            ? ' '.ucfirst($environment->name).' still serves '.($serving->commit_sha !== null ? substr($serving->commit_sha, 0, 7) : 'the previous version').'.'
            : '';
        $facts = array_filter([
            'Commit' => $sha,
            'Step' => $deployment->failure_class?->value,
        ], static fn (?string $v): bool => $v !== null);

        // An app update (D106) says which version didn't go live and which keeps running.
        $update = $deployment->trigger === DeploymentTrigger::Update ? AppUpdate::query()->where('deployment_id', $deployment->id)->first() : null;

        $this->raise->handle(new AlertSignal(
            team: $deployment->team,
            rule: AlertRule::DeployFailed,
            subject: $subject,
            title: $update instanceof AppUpdate
                ? "{$instance->service->name}’s update to {$update->to_version} didn’t go live in {$environment->name}"
                : "{$instance->service->name} failed to deploy to {$environment->name}",
            summary: $update instanceof AppUpdate ? "{$reason} {$update->from_version} keeps running." : $reason.$reassurance,
            severity: $environment->kind === EnvironmentKind::Production ? AlertSeverity::Critical : AlertSeverity::Warning,
            href: Links::deployment($deployment),
            project: $environment->project,
            environment: $environment,
            serviceInstance: $instance,
            facts: $facts,
            occurrence: $deployment->public_id,
        ));
    }

    /**
     * The app update a probation rollback undid, if the version it replaced
     * came from one.
     */
    private function updateBefore(Deployment $rollback): ?AppUpdate
    {
        return AppUpdate::query()
            ->where('service_instance_id', $rollback->service_instance_id)
            ->where('deployment_id', '<', $rollback->id)
            ->whereIn('status', [AppUpdateStatus::Watching->value, AppUpdateStatus::RolledBack->value])
            ->where('started_at', '>=', now()->subDay())
            ->latest('id')
            ->first(['id', 'to_version', 'deployment_id', 'started_at']);
    }
}
