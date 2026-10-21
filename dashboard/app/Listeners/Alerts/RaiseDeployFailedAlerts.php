<?php

declare(strict_types=1);

namespace App\Listeners\Alerts;

use App\Actions\Alerts\RaiseAlert;
use App\Actions\Alerts\ResolveAlert;
use App\Data\Alerts\AlertSignal;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\DeploymentStatus;
use App\Enums\EnvironmentKind;
use App\Events\DeploymentStatusChanged;
use App\Models\Deployment;
use Throwable;

/**
 * `deploy_failed` (docs/15 §3.4): a failed deployment of a lasting
 * environment raises an alert for its service; the service's next live
 * deployment resolves it. Previews never alert. A listener never changes
 * the deployment's outcome, so failures are reported, not thrown.
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

        $this->raise->handle(new AlertSignal(
            team: $deployment->team,
            rule: AlertRule::DeployFailed,
            subject: $subject,
            title: "{$instance->service->name} failed to deploy to {$environment->name}",
            summary: $reason.$reassurance,
            severity: $environment->kind === EnvironmentKind::Production ? AlertSeverity::Critical : AlertSeverity::Warning,
            href: "/p/{$environment->project->public_id}/{$environment->public_id}/d/{$deployment->public_id}",
            project: $environment->project,
            environment: $environment,
            serviceInstance: $instance,
            facts: $facts,
            occurrence: $deployment->public_id,
        ));
    }
}
