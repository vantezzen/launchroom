<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Deployments\FailureExplanationData;
use App\Data\Protocol\V1\BuildWarning;
use App\Data\Specs\ServiceSpec;
use App\Enums\DaemonTaskType;
use App\Enums\DeploymentStepStatus;
use App\Enums\FailureFixAction;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Models\LogChunk;
use App\Models\Server;
use App\Services\Deployments\DeploymentLogMasker;
use App\Support\Deployments\Failures\FailureCatalogue;
use App\Support\Deployments\Failures\FailureContext;

/**
 * A failed deployment's known failure in plain words (FailureCatalogue,
 * docs/04 §5): what happened, why, how to fix it and the one-click fix.
 * The deployment page and the API (`getDeploymentFailure`) read the same
 * explanation, so the CLI says what the dashboard says.
 */
final readonly class FailureExplanations
{
    /** Answers worth quoting: the app's own words say what is wrong. */
    private const array QUOTES_ANSWER = ['host_not_allowed', 'health_status', 'django_disallowed_host', 'rails_blocked_host'];

    public function __construct(private DeploymentLogMasker $masker) {}

    /**
     * @param  list<DeploymentStep>|null  $steps  the deployment's steps, when the caller has them loaded
     */
    public function explain(Deployment $deployment, ?ServiceSpec $service, ?array $steps = null): ?FailureExplanationData
    {
        if ($deployment->failure_class === null) {
            return null;
        }

        $steps ??= array_values($deployment->steps()->orderBy('id')->get()->all());
        $build = $this->buildTask($deployment);
        $hints = $this->hints($build);
        $codes = array_map(static fn (BuildWarning $hint): string => $hint->code, $hints);

        // launchroomd's hint messages carry facts the log doesn't ("freed 3.1 GB").
        $stepMessages = array_map(static fn (BuildWarning $hint): string => $hint->message, $hints);
        $listening = [];
        $hintPort = null;
        // The failed replica's facts (RolloutStatus::failureMeta, A3a).
        $facts = [];
        $adopted = false;

        foreach ($steps as $step) {
            if ($step->status !== DeploymentStepStatus::Failed) {
                continue;
            }

            // launchroom already set the port it found (AdoptDetectedPort).
            $adopted = $adopted || isset($step->meta['adoptedAt']);

            if (is_string($step->meta['message'] ?? null)) {
                $stepMessages[] = $step->meta['message'];
            }

            if (is_string($step->meta['portHint'] ?? null)) {
                $codes[] = $step->meta['portHint'];
                $listening = array_values(array_filter((array) ($step->meta['listening'] ?? []), is_int(...)));
                $hintPort = is_int($step->meta['port'] ?? null) ? $step->meta['port'] : null;
            }

            foreach (['failureCode', 'probeHint'] as $key) {
                if (is_string($step->meta[$key] ?? null)) {
                    $codes[] = $step->meta[$key];
                }
            }

            $facts = [...$facts, ...array_intersect_key($step->meta, array_flip(['exitCode', 'oomKilled', 'signal', 'byLaunchroom', 'server', 'probeBody']))];
        }

        if ($build instanceof DaemonTask) {
            $tail = LogChunk::query()->where('team_id', $deployment->team_id)
                ->where('daemon_task_id', $build->id)->latest('id')->limit(8)->get(['lines'])
                ->reverse()->flatMap(static fn (LogChunk $chunk): array => array_column($chunk->lines, 'text'))->implode("\n");
            $stepMessages[] = mb_substr($tail, -16000);
        }

        // Where it failed: the replica's server, else the build server (a full disk during the build).
        $serverId = is_string($facts['server'] ?? null) ? $facts['server'] : null;
        $server = $serverId !== null
            ? Server::query()->where('team_id', $deployment->team_id)->where('public_id', $serverId)->first(['id', 'public_id', 'name'])
            : ($build?->server_id !== null ? Server::query()->where('team_id', $deployment->team_id)->whereKey($build->server_id)->first(['id', 'public_id', 'name']) : null);

        // The probe's answer is the app's output: masked like a log line.
        $body = is_string($facts['probeBody'] ?? null) && $facts['probeBody'] !== '' ? $this->masker->mask($deployment->id, $facts['probeBody']) : null;

        $explanation = FailureCatalogue::explain(new FailureContext(
            class: $deployment->failure_class,
            message: $deployment->failure_message,
            hintCodes: array_values(array_unique($codes)),
            logExcerpt: $stepMessages === [] ? null : implode("\n", $stepMessages),
            currentPort: $service?->deploy->port,
            healthStartPeriodSeconds: $service?->deploy->healthcheck->startPeriodSeconds,
            listening: $listening,
            hintPort: $hintPort,
            exitCode: is_int($facts['exitCode'] ?? null) ? $facts['exitCode'] : null,
            oomKilled: ($facts['oomKilled'] ?? false) === true,
            signal: is_int($facts['signal'] ?? null) ? $facts['signal'] : null,
            byLaunchroom: ($facts['byLaunchroom'] ?? false) === true,
            server: $server?->name,
            serverId: $server?->public_id,
            imageUser: $deployment->imageFacts()?->user,
            probeBody: $body,
        ));

        if (! $explanation instanceof FailureExplanationData) {
            return null;
        }

        $action = $adopted && $explanation->action === FailureFixAction::SetPort ? null : $explanation->action;

        return new FailureExplanationData(
            key: $explanation->key,
            title: $explanation->title,
            cause: $explanation->cause,
            fix: $explanation->fix,
            action: $action,
            params: $action instanceof FailureFixAction ? $explanation->params : [],
            answer: $body !== null && in_array($explanation->key, self::QUOTES_ANSWER, true) ? $body : null,
        );
    }

    /**
     * The known build problems launchroomd recognised (BuildHintCode), with
     * its message for each.
     *
     * @return list<BuildWarning>
     */
    public function buildHints(Deployment $deployment): array
    {
        return $this->hints($this->buildTask($deployment));
    }

    private function buildTask(Deployment $deployment): ?DaemonTask
    {
        return DaemonTask::query()
            ->where('team_id', $deployment->team_id)
            ->where('deployment_id', $deployment->id)
            ->where('type', DaemonTaskType::Build->value)
            ->latest('id')
            ->first(['id', 'result', 'server_id']);
    }

    /**
     * @return list<BuildWarning>
     */
    private function hints(?DaemonTask $build): array
    {
        return $build?->result?->failure->hints ?? [];
    }
}
