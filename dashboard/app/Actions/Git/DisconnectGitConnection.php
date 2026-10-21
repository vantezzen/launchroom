<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\Git\GitConnection;
use App\Models\Git\GitRepository;
use App\Services\Git\GitProviders;
use App\Services\Git\Providers\ApiGitProvider;
use Illuminate\Support\Facades\DB;

/**
 * Disconnects a GitLab/Gitea/plain git connection (docs/19 §5): removes the
 * webhooks launchroom registered (best effort), then deletes the connection
 * with its credentials and repository cache. Services sourced from it stop
 * receiving pushes and can't build until it is connected again.
 */
final readonly class DisconnectGitConnection
{
    public function __construct(
        private GitProviders $providers,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(GitConnection $connection, AuditActor $actor): void
    {
        $provider = $this->providers->for($connection->provider);

        if ($provider instanceof ApiGitProvider && $connection->isActive()) {
            foreach ($connection->repositories()->whereNotNull('hook_id')->get() as $repository) {
                /** @var GitRepository $repository */
                $repository->setRelation('gitConnection', $connection);
                $provider->removeWebhook($repository);
            }
        }

        DB::transaction(function () use ($connection, $actor): void {
            $this->audit->handle(new AuditEntryData(AuditAction::GitConnectionDisconnected, $actor, $connection->team_id, 'git_connection', $connection->public_id, [
                'provider' => $connection->provider->value,
                'server' => $connection->host(),
            ]));
            $connection->delete();
        });
    }
}
