<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Contracts\Git\TrackedRefs;
use App\Data\Git\TrackedRef;
use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\GitProviderKind;
use App\Models\Git\GitConnection;
use App\Models\Git\GitHubInstallation;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every minute: poll tracked refs of GitHub installations and git
 * connections without a recent verified webhook (docs/06 §2, docs/19). One
 * failing ref never stops the others.
 */
final readonly class PollStaleTrackedRefs
{
    public function __construct(
        private TrackedRefs $trackedRefs,
        private PollTrackedRef $pollRef,
        private PollConnectionRef $pollConnectionRef,
    ) {}

    /**
     * @return int number of refs polled
     */
    public function handle(): int
    {
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

        $polled = $this->pollConnections($byConnection);

        if ($byInstallation === []) {
            return $polled;
        }

        $installations = GitHubInstallation::query()
            ->with('app')
            ->active()
            ->whereIn('id', array_keys($byInstallation))
            ->get();

        foreach ($installations as $installation) {
            if ($installation->app->hasHealthyWebhooks()) {
                continue;
            }

            foreach ($byInstallation[$installation->id] ?? [] as $ref) {
                try {
                    $this->pollRef->handle($installation, $ref->repositoryId, $ref->ref);
                    $polled++;
                } catch (Throwable $e) {
                    Log::warning('Polling a tracked ref failed', [
                        'installation' => $installation->public_id,
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
     * @param  array<int, list<TrackedRef>>  $byConnection
     */
    private function pollConnections(array $byConnection): int
    {
        if ($byConnection === []) {
            return 0;
        }

        $polled = 0;
        $connections = GitConnection::query()
            ->where('status', GitConnectionStatus::Active->value)
            ->whereIn('id', array_keys($byConnection))
            ->get();

        foreach ($connections as $connection) {
            if ($connection->hasHealthyWebhooks()) {
                continue;
            }

            foreach ($byConnection[$connection->id] ?? [] as $ref) {
                if ($ref->provider !== $connection->provider) {
                    continue;
                }

                try {
                    $this->pollConnectionRef->handle($connection, $ref->repositoryId, $ref->ref);
                    $polled++;
                } catch (Throwable $e) {
                    Log::warning('Polling a tracked ref failed', [
                        'connection' => $connection->public_id,
                        'repository' => $ref->repositoryId,
                        'ref' => $ref->ref,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $polled;
    }
}
