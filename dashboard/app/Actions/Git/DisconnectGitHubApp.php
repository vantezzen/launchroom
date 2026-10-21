<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\Git\GitHubApp;
use Illuminate\Support\Facades\DB;

/**
 * Removes a GitHub App from launchroom: its credentials, installations and
 * repository cache. The app itself stays on GitHub (only its owner can delete
 * it there); without the private key it can do nothing for launchroom.
 */
final readonly class DisconnectGitHubApp
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(GitHubApp $app, AuditActor $actor): void
    {
        DB::transaction(function () use ($app, $actor): void {
            $this->audit->handle(new AuditEntryData(
                action: AuditAction::GitHubAppDisconnected,
                actor: $actor,
                teamId: $app->team_id,
                subjectType: 'github_app',
                subjectId: $app->public_id,
                meta: ['app_id' => $app->app_id, 'slug' => $app->slug],
            ));

            $app->delete();
        });
    }
}
