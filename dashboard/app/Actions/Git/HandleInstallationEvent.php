<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Enums\Git\WebhookDeliveryStatus;
use App\Jobs\Git\RefreshInstallationRepositoriesJob;
use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubInstallation;
use App\Services\GitHub\Dto\InstallationInfo;
use App\Services\GitHub\Dto\RepositoryInfo;
use App\Services\GitHub\JsonObject;
use Illuminate\Support\Facades\DB;

/**
 * `installation` and `installation_repositories` webhooks: keep the
 * installation and its repository cache in sync. Uninstalling marks the
 * installation removed (services keep their source but can't build until it
 * is reinstalled).
 */
final readonly class HandleInstallationEvent
{
    public function __construct(
        private SyncGitHubInstallation $syncInstallation,
        private SyncInstallationRepositories $syncRepositories,
    ) {}

    public function handle(GitHubApp $app, string $event, ?string $action, JsonObject $payload): WebhookDeliveryStatus
    {
        $info = InstallationInfo::fromJson($payload->object('installation'));

        if ($info->appId !== $app->app_id) {
            return WebhookDeliveryStatus::Ignored;
        }

        if ($event === 'installation' && $action === 'deleted') {
            $this->remove($app, $info->id);

            return WebhookDeliveryStatus::Processed;
        }

        $installation = DB::transaction(function () use ($app, $info, $event, $payload): GitHubInstallation {
            $installation = $this->syncInstallation->handle($app, $info);

            if ($event === 'installation') {
                // `created` lists the accessible repositories (id, name, private).
                $this->syncRepositories->handle($installation, $this->repositories($payload, 'repositories'));
            } else {
                $removed = array_map(
                    static fn (JsonObject $repository): int => $repository->int('id'),
                    $payload->objects('repositories_removed'),
                );

                $this->syncRepositories->handle($installation, $this->repositories($payload, 'repositories_added'), $removed);
            }

            return $installation;
        });

        // Payload lists lack default branches; fill the cache in from the API.
        if ($installation->isActive()) {
            dispatch(new RefreshInstallationRepositoriesJob($installation->id))->afterCommit();
        }

        return WebhookDeliveryStatus::Processed;
    }

    /**
     * @return list<RepositoryInfo>
     */
    private function repositories(JsonObject $payload, string $key): array
    {
        return array_map(RepositoryInfo::fromJson(...), $payload->objects($key));
    }

    private function remove(GitHubApp $app, int $installationId): void
    {
        DB::transaction(function () use ($app, $installationId): void {
            $installation = $app->installations()->where('installation_id', $installationId)->lockForUpdate()->first();

            if (! $installation instanceof GitHubInstallation) {
                return;
            }

            $installation->forceFill(['removed_at' => now()])->save();
            $installation->repositories()->delete();
        });
    }
}
