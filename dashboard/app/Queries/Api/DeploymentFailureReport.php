<?php

declare(strict_types=1);

namespace App\Queries\Api;

use App\Data\Api\ApiFailureExplanationData;
use App\Data\Api\ApiFailureFixData;
use App\Data\Api\ApiFailureHintData;
use App\Data\Api\ApiFailureReportData;
use App\Data\Api\ApiLogExcerptLineData;
use App\Data\Deployments\DeploymentFailureData;
use App\Data\Deployments\FailureExplanationData;
use App\Data\Deployments\LogLineData;
use App\Data\Protocol\V1\BuildWarning;
use App\Data\Specs\ServiceSpec;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentStepName;
use App\Enums\DeploymentStepStatus;
use App\Enums\FailureFixAction;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Queries\DeploymentLogs;
use App\Queries\FailureExplanations;
use App\Support\Logs\AnsiText;

/**
 * `GET /deployments/{deployment}/failure` (docs/04 §5, docs/16 §2.2): what
 * the deployment page's failure card says, for scripts, the CLI and MCP.
 *
 * The excerpt is the failing step's last lines, plus the first line that
 * reads like an error (with a line of context) when it came earlier: the
 * same line the page's "Jump to line" opens. Stored log lines are already
 * masked against the deployment's secrets (StoreLogBatch); ANSI escapes
 * are removed.
 */
final readonly class DeploymentFailureReport
{
    /** Last lines of the failing step quoted in the excerpt. */
    public const int TAIL = 12;

    /** At most this many log lines are read to find the failing step's (a huge build log stops here). */
    private const int MAX_LINES = 100_000;

    /** resources/js/features/logs/step-log.ts ERROR_PATTERN. */
    private const string ERROR = '/(\berror\b|\bfailed\b|\bfatal\b|\bexception\b|ERR!|ERR_[A-Z_]+|\bpanic:|Traceback \(most recent call last\))/i';

    /** A failed build cache import is a note, never an error (step-log.ts CACHE_NOTE + CACHE_IMPORT_ERROR). */
    private const string CACHE_NOTE = '/^(#\d+ )?(No build cache yet for this service|WARNING: Couldn\'t load the build cache|ERROR: failed to configure registry cache importer)/';

    public function __construct(
        private FailureExplanations $explanations,
        private DeploymentLogs $logs,
    ) {}

    public function get(Deployment $deployment): ?ApiFailureReportData
    {
        $class = $deployment->failure_class;

        if ($deployment->status !== DeploymentStatus::Failed || $class === null) {
            return null;
        }

        $deployment->loadMissing(['serviceInstance.service', 'environment.project', 'revision']);
        $environment = $deployment->environment;
        $service = $deployment->revision?->spec->service($deployment->serviceInstance->service->public_id);
        $steps = array_values($deployment->steps()->orderBy('id')->get()->all());
        $explanation = $this->explanations->explain($deployment, $service instanceof ServiceSpec ? $service : null, $steps);
        $general = DeploymentFailureData::for($class, $deployment->failure_message);
        $failing = $this->failingStep($steps);
        [$excerpt, $errorLine] = $failing instanceof DeploymentStepName ? $this->excerpt($deployment, $failing) : [[], null];
        $serving = $deployment->serviceInstance->active_deployment_id === null || $deployment->serviceInstance->active_deployment_id === $deployment->id
            ? null
            : Deployment::query()->where('team_id', $deployment->team_id)->whereKey($deployment->serviceInstance->active_deployment_id)->value('commit_sha');
        $dashboardUrl = route('deployments.show', [$environment->project->public_id, $environment->public_id, $deployment->public_id]);

        return new ApiFailureReportData(
            deploymentId: $deployment->public_id,
            class: $class,
            title: $general->title,
            message: $deployment->failure_message,
            hint: $general->hint,
            explanation: $explanation instanceof FailureExplanationData ? new ApiFailureExplanationData(
                key: $explanation->key,
                title: $explanation->title,
                cause: $explanation->cause,
                fix: $explanation->fix,
                answer: $explanation->answer,
            ) : null,
            hints: array_map(static fn (BuildWarning $hint): ApiFailureHintData => new ApiFailureHintData($hint->code, $hint->message), $this->explanations->buildHints($deployment)),
            failingStep: $failing,
            excerpt: $excerpt,
            errorLine: $errorLine,
            fixes: $explanation?->action instanceof FailureFixAction ? [$this->fix($deployment, $explanation, $explanation->action, $dashboardUrl)] : [],
            servingCommit: is_string($serving) ? $serving : null,
            dashboardUrl: $dashboardUrl,
        );
    }

    /**
     * @param  list<DeploymentStep>  $steps
     */
    private function failingStep(array $steps): ?DeploymentStepName
    {
        foreach ($steps as $step) {
            if ($step->status === DeploymentStepStatus::Failed) {
                return $step->name;
            }
        }

        return null;
    }

    /**
     * @return array{list<ApiLogExcerptLineData>, int|null}
     */
    private function excerpt(Deployment $deployment, DeploymentStepName $step): array
    {
        /** @var list<LogLineData> $lines */
        $lines = [];
        $after = 0;
        $read = 0;

        do {
            $page = $this->logs->page($deployment, $after);
            $read += count($page->lines);
            $after = $page->cursor;

            foreach ($page->lines as $line) {
                if ($line->section === $step->value) {
                    $lines[] = $line;
                }
            }
        } while ($page->hasMore && $read < self::MAX_LINES && $page->lines !== []);

        $plain = [];

        foreach ($lines as $line) {
            $text = AnsiText::strip($line->text);

            if (preg_match(self::CACHE_NOTE, $text) !== 1) {
                $plain[] = new ApiLogExcerptLineData($line->number, $line->stream, mb_substr($text, 0, 2000), preg_match(self::ERROR, $text) === 1);
            }
        }

        if ($plain === []) {
            return [[], null];
        }

        // Where it failed (step-log.ts failedStepErrors): the first error line, else the first stderr line, else the last.
        $first = array_find($plain, static fn (ApiLogExcerptLineData $line): bool => $line->error)
            ?? array_find($plain, static fn (ApiLogExcerptLineData $line): bool => $line->stream === 'stderr')
            ?? $plain[count($plain) - 1];
        $first->error = true;

        $at = array_search($first, $plain, true);
        $tailFrom = max(0, count($plain) - self::TAIL);
        $keep = range($tailFrom, count($plain) - 1);

        if (is_int($at) && $at < $tailFrom) {
            $keep = [...range(max(0, $at - 1), min($at + 1, $tailFrom - 1)), ...$keep];
        }

        return [array_values(array_map(static fn (int $index): ApiLogExcerptLineData => $plain[$index], array_unique($keep))), $first->number];
    }

    private function fix(Deployment $deployment, FailureExplanationData $explanation, FailureFixAction $action, string $dashboardUrl): ApiFailureFixData
    {
        $params = $explanation->params;
        $int = static fn (string $key): ?int => isset($params[$key]) && is_numeric($params[$key]) ? (int) $params[$key] : null;
        $string = static fn (string $key): ?string => isset($params[$key]) && $params[$key] !== '' ? (string) $params[$key] : null;

        return new ApiFailureFixData(
            code: $action,
            label: $action->label($params),
            oneClick: $action->isOneClick(),
            command: $this->command($deployment, $action, $params),
            dashboardUrl: $dashboardUrl,
            port: $action === FailureFixAction::SetPort ? $int('port') : null,
            seconds: $action === FailureFixAction::RaiseHealthTimeout ? ($int('suggestedSeconds') ?? 60) : null,
            server: $action === FailureFixAction::FreeDisk ? $string('server') : null,
            variable: $action === FailureFixAction::AddVariable ? $string('name') : null,
        );
    }

    /**
     * The CLI line that applies the fix: `launchroom fix <code>` for the
     * one-click fixes, `launchroom env set` for a missing variable.
     *
     * @param  array<string, string|int>  $params
     */
    private function command(Deployment $deployment, FailureFixAction $action, array $params): ?string
    {
        if ($action->isOneClick()) {
            return "launchroom fix {$action->value} --deployment {$deployment->public_id}";
        }

        if ($action !== FailureFixAction::AddVariable || ! isset($params['name'])) {
            return null;
        }

        $name = (string) $params['name'];
        $value = isset($params['value']) && $params['value'] !== '' ? (string) $params['value'] : '<value>';
        $secret = ($params['type'] ?? null) === 'secret' ? ' --secret' : '';

        return 'launchroom env set '.$this->quoted("{$name}={$value}")."{$secret} --service {$deployment->serviceInstance->service->public_id} --environment {$deployment->environment->public_id} --apply";
    }

    /** A shell word for a copyable command line: quoted unless it is plain. */
    private function quoted(string $word): string
    {
        return preg_match('/^[A-Za-z0-9_.,:\/=@%+-]+$/D', $word) === 1 ? $word : "'".str_replace("'", "'\\''", $word)."'";
    }
}
