<?php

declare(strict_types=1);

namespace App\Services\Git\Feedback;

use App\Data\Git\DeploymentStatusReport;
use App\Enums\DeploymentStatus;
use App\Enums\Git\DeploymentReportState;
use App\Models\Deployment;
use App\Models\Git\GitHubPullRequestComment;
use App\Models\Git\GitPullRequestComment;
use App\Models\Previews\Preview;
use App\Support\Variables\SystemVariables;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Pull request feedback shared by every provider (docs/06 §5, docs/19 §1):
 * the single launchroom comment (one row per service and environment,
 * updated in place, re-created when someone deleted it, retired when a
 * preview goes away), the preview's aggregate state, and the cap on a pull
 * request's changed files. Providers supply only the transport
 * ({@see PullRequestThread}) and their status calls.
 */
final readonly class PullRequestFeedback
{
    public const string COMMENT_MARKER = '<!-- launchroom:deployments -->';

    /** More changed files than this (GitHub's compare API stops at 300): unknown, every service rebuilds. */
    public const int MAX_CHANGED_FILES = 300;

    /**
     * The changed files to use, or null when unknown or too many.
     *
     * @param  list<string>|null  $files
     * @return list<string>|null
     */
    public static function changedFiles(?array $files): ?array
    {
        return $files === null || count($files) >= self::MAX_CHANGED_FILES ? null : array_values(array_unique($files));
    }

    /**
     * Runs one preview's report under its lock, so concurrent reports
     * serialize on every provider.
     *
     * @param  Closure(): void  $report
     */
    public function forPreview(Preview $preview, Closure $report): void
    {
        Cache::lock('launchroom:git:preview:'.$preview->id, 30)->block(20, $report);
    }

    public function recordDeployment(PullRequestThread $thread, DeploymentStatusReport $report): void
    {
        Cache::lock($thread->lockKey, 30)->block(20, function () use ($thread, $report): void {
            $comment = $thread->comment(true);

            if ($comment === null) {
                return;
            }

            $rows = $comment->rows;
            $rows["{$report->serviceName}|{$report->environmentName}"] = [
                'service' => $report->serviceName,
                'environment' => $report->environmentName,
                'state' => $report->state->value,
                'url' => $report->environmentUrl,
                'logUrl' => $report->deploymentUrl,
                'sha' => $report->commitSha,
                'updatedAt' => CarbonImmutable::now()->toIso8601ZuluString(),
            ];
            ksort($rows);
            $comment->rows = $rows;
            $comment->save();

            $this->publish($thread, $comment, self::render($rows));
        });
    }

    /**
     * Every row of a removed preview says it's gone (matched by its GitHub
     * environment name or its slug).
     */
    public function retirePreview(PullRequestThread $thread, Preview $preview): void
    {
        Cache::lock($thread->lockKey, 30)->block(20, function () use ($thread, $preview): void {
            $comment = $thread->comment(false);

            if ($comment === null || $comment->comment_id === null) {
                return;
            }

            $rows = $comment->rows;

            foreach ($rows as $key => $entry) {
                if ($entry['environment'] === $preview->githubEnvironment() || $entry['environment'] === $preview->slug()) {
                    $rows[$key] = [...$entry, 'state' => DeploymentReportState::Cancelled->value, 'url' => null];
                }
            }

            $comment->rows = $rows;
            $comment->save();

            $this->publish($thread, $comment, self::render($rows)."\n_The preview environment was removed ({$preview->status->label()})._\n");
        });
    }

    public function previewState(Preview $preview): PreviewFeedback
    {
        $deployments = Deployment::query()
            ->where('environment_id', $preview->environment_id)
            ->currentPerInstance()
            ->with(['serviceInstance.service', 'revision'])
            ->orderByDesc('id')
            ->get();

        if ($deployments->isEmpty()) {
            return new PreviewFeedback(DeploymentReportState::InProgress, null, 'Preparing the preview.');
        }

        $failed = $deployments->first(static fn (Deployment $deployment): bool => $deployment->status === DeploymentStatus::Failed);

        if ($failed instanceof Deployment) {
            return new PreviewFeedback(DeploymentReportState::Failure, null, "{$failed->serviceInstance->service->name} failed: ".($failed->failure_message ?? 'see launchroom'));
        }

        if (! $deployments->every(static fn (Deployment $deployment): bool => $deployment->status === DeploymentStatus::Active)) {
            return new PreviewFeedback(DeploymentReportState::InProgress, null, 'Deploying the preview.');
        }

        foreach ($deployments as $deployment) {
            $spec = $deployment->revision?->spec->service($deployment->serviceInstance->service->public_id);
            $domain = $spec === null || ! $spec->kind->isWeb() ? null : SystemVariables::primaryDomain($spec);

            if ($domain !== null) {
                return new PreviewFeedback(DeploymentReportState::Success, 'https://'.$domain, 'The preview is live.');
            }
        }

        return new PreviewFeedback(DeploymentReportState::Success, null, 'The preview is live.');
    }

    /**
     * @param  array<string, array{service: string, environment: string, state: string, url: string|null, logUrl: string, sha: string, updatedAt: string}>  $rows
     */
    public static function render(array $rows): string
    {
        $lines = [
            self::COMMENT_MARKER,
            '**launchroom** deployments for this pull request',
            '',
            '| Service | Environment | Status | Preview | Commit |',
            '| :-- | :-- | :-- | :-- | :-- |',
        ];

        foreach ($rows as $row) {
            $state = DeploymentReportState::tryFrom($row['state']);
            $lines[] = sprintf(
                '| %s | %s | %s ([logs](%s)) | %s | `%s` |',
                self::cell($row['service']),
                self::cell($row['environment']),
                $state?->label() ?? 'Unknown',
                $row['logUrl'],
                $row['url'] !== null && $state === DeploymentReportState::Success ? "[Visit]({$row['url']})" : '–',
                substr($row['sha'], 0, 7),
            );
        }

        return implode("\n", $lines)."\n";
    }

    private function publish(PullRequestThread $thread, GitPullRequestComment|GitHubPullRequestComment $comment, string $body): void
    {
        if ($comment->comment_id !== null && $thread->update($comment->comment_id, $body)) {
            return;
        }

        $comment->comment_id = $thread->create($body);
        $comment->save();
    }

    private static function cell(string $value): string
    {
        return str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], $value);
    }
}
