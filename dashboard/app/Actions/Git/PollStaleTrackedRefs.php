<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Contracts\Git\TrackedRefs;
use App\Data\Git\TrackedRef;
use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\GitProviderKind;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRefHead;
use App\Models\Git\GitRefHead;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every minute (docs/06 §2.2, docs/19): first records missed pushes (a head
 * polling found that no webhook delivered within the grace period), then
 * polls tracked refs: every minute for GitHub apps and git connections
 * whose webhooks aren't healthy, every few minutes as a safety net for the
 * rest. One failing ref never stops the others.
 */
final readonly class PollStaleTrackedRefs
{
    public function __construct(
        private TrackedRefs $trackedRefs,
        private PollTrackedRef $pollRef,
    ) {}

    /**
     * @return int number of refs polled
     */
    public function handle(): int
    {
        $this->recordMissedPushes();

        /** @var array<int, list<TrackedRef>> $byInstallation */
        $byInstallation = [];
        /** @var array<int, list<TrackedRef>> $byConnection */
        $byConnection = [];
        $seen = [];

        foreach ($this->trackedRefs->all() as $ref) {
            $key = "{$ref->provider->value}|{$ref->installationId}|{$ref->repositoryId}|{$ref->ref}";

            if (! isset($seen[$key])) {
                $seen[$key] = true;

                if ($ref->provider === GitProviderKind::GitHub) {
                    $byInstallation[$ref->installationId][] = $ref;
                } else {
                    $byConnection[$ref->installationId][] = $ref;
                }
            }
        }

        $accounts = [
            ...($byInstallation === [] ? [] : GitHubInstallation::query()->with('app')->active()->whereIn('id', array_keys($byInstallation))->get()->all()),
            ...($byConnection === [] ? [] : GitConnection::query()->where('status', GitConnectionStatus::Active->value)->whereIn('id', array_keys($byConnection))->get()->all()),
        ];

        $polled = 0;

        foreach ($accounts as $account) {
            $refs = $account instanceof GitHubInstallation ? $byInstallation[$account->id] ?? [] : $byConnection[$account->id] ?? [];
            $recent = $this->recentlyPolled($account);

            foreach ($refs as $ref) {
                if ($ref->provider !== $account->gitProvider() || isset($recent["{$ref->repositoryId}|{$ref->ref}"])) {
                    continue;
                }

                try {
                    $this->pollRef->handle($account, $ref->repositoryId, $ref->ref);
                    $polled++;
                } catch (Throwable $e) {
                    Log::warning('Polling a tracked ref failed', [
                        'account' => $account->public_id,
                        'repository' => $ref->repositoryId,
                        'ref' => $ref->ref,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $polled;
    }

    /**
     * Refs polled within the safety interval, which healthy webhooks skip
     * this minute.
     *
     * @return array<string, true> keyed "<repository>|<ref>"
     */
    private function recentlyPolled(GitHubInstallation|GitConnection $account): array
    {
        $heads = match (true) {
            $account instanceof GitHubInstallation => $account->app->hasHealthyWebhooks()
                ? GitHubRefHead::query()->where('github_installation_id', $account->id)->get()->map(fn (GitHubRefHead $head): array => [$head->github_repo_id, $head->ref, $head->last_polled_at])->all()
                : [],
            default => $account->hasHealthyWebhooks()
                ? GitRefHead::query()->where('git_connection_id', $account->id)->get()->map(fn (GitRefHead $head): array => [$head->external_id, $head->ref, $head->last_polled_at])->all()
                : [],
        };

        $since = now()->subSeconds(config()->integer('launchroom.github.safety_poll_seconds'));
        $out = [];

        foreach ($heads as [$repositoryId, $ref, $polledAt]) {
            if ($polledAt?->greaterThan($since) === true) {
                $out["{$repositoryId}|{$ref}"] = true;
            }
        }

        return $out;
    }

    /**
     * Heads that polling found and no webhook delivered within the grace
     * period mark their GitHub app or git connection as missing pushes
     * (`webhook_missed_at`) until the next verified delivery.
     */
    private function recordMissedPushes(): void
    {
        $grace = config()->integer('launchroom.github.webhook_grace_seconds');
        $cutoff = now()->subSeconds($grace);

        foreach (GitHubRefHead::query()->with('installation.app')->where('awaiting_webhook_since', '<=', $cutoff)->get() as $head) {
            $this->recordMiss($head->installation->app, $head->awaiting_webhook_since, $grace);
            $head->forceFill(['awaiting_webhook_since' => null])->save();
        }

        foreach (GitRefHead::query()->with('connection')->where('awaiting_webhook_since', '<=', $cutoff)->get() as $head) {
            $this->recordMiss($head->connection, $head->awaiting_webhook_since, $grace);
            $head->forceFill(['awaiting_webhook_since' => null])->save();
        }
    }

    private function recordMiss(GitHubApp|GitConnection $owner, ?CarbonImmutable $awaitingSince, int $grace): void
    {
        $missedAt = ($awaitingSince ?? now()->toImmutable())->addSeconds($grace);

        if ($owner->webhook_missed_at === null || $owner->webhook_missed_at->lessThan($missedAt)) {
            $owner->forceFill(['webhook_missed_at' => $missedAt])->saveQuietly();
        }
    }
}
