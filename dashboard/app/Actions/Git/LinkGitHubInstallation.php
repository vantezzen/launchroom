<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Git\RefreshInstallationRepositoriesJob;
use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubInstallation;
use App\Models\Team;
use App\Services\GitHub\Dto\InstallationInfo;
use App\Services\GitHub\GitHubClientFactory;
use Illuminate\Support\Facades\DB;

/**
 * The setup callback after a user installs the app. `installation_id` comes
 * from a URL anyone can craft, so it is only trusted after GitHub confirms it
 * belongs to one of *this team's* apps (`GET /app/installations/{id}` with the
 * App JWT; docs/09 R18).
 */
final readonly class LinkGitHubInstallation
{
    public function __construct(
        private GitHubClientFactory $clients,
        private SyncGitHubInstallation $sync,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Team $team, int $installationId, AuditActor $actor): GitHubInstallation
    {
        $apps = GitHubApp::query()->where('team_id', $team->id)->orderBy('id')->get();

        foreach ($apps as $app) {
            $info = $this->clients->for($app)->installation($installationId);

            if (! $info instanceof InstallationInfo) {
                continue;
            }

            $installation = DB::transaction(function () use ($app, $info, $team, $actor): GitHubInstallation {
                $installation = $this->sync->handle($app, $info);

                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::GitHubInstallationLinked,
                    actor: $actor,
                    teamId: $team->id,
                    subjectType: 'github_installation',
                    subjectId: $installation->public_id,
                    meta: ['installation_id' => $info->id, 'account' => $info->accountLogin],
                ));

                return $installation;
            });

            dispatch(new RefreshInstallationRepositoriesJob($installation->id))->afterCommit();

            return $installation;
        }

        throw DomainRuleViolation::on('github', 'GitHub doesn’t confirm this installation for your team’s GitHub App.');
    }
}
