<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Git\ManifestState;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Git\GitHubApp;
use App\Models\User;
use App\Services\GitHub\Dto\ManifestConversion;
use App\Services\GitHub\Exceptions\GitHubRequestFailed;
use App\Services\GitHub\GitHubApi;
use App\Services\GitHub\GitHubAppSecrets;
use App\Services\GitHub\JsonObject;
use App\Support\PublicId;
use Illuminate\Support\Facades\DB;

/**
 * Converts the manifest code into the app's credentials
 * (`POST /app-manifests/{code}/conversions`) and stores them
 * envelope-encrypted (docs/09 R13).
 */
final readonly class RegisterGitHubApp
{
    public function __construct(
        private GitHubApi $api,
        private GitHubAppSecrets $secrets,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ManifestState $state, string $code, User $user, AuditActor $actor): GitHubApp
    {
        if (preg_match('/^[A-Za-z0-9]{1,64}$/D', $code) !== 1) {
            throw DomainRuleViolation::on('github', 'GitHub returned an invalid setup code. Start again from Settings → Git.');
        }

        try {
            $response = $this->api->send($state->apiUrl, 'POST', "/app-manifests/{$code}/conversions");
        } catch (GitHubRequestFailed) {
            throw DomainRuleViolation::on('github', 'GitHub didn’t accept the setup code (codes expire after one hour and work once). Start again from Settings → Git.');
        }

        $credentials = ManifestConversion::fromJson(JsonObject::decode($response->body()));

        $exists = GitHubApp::query()
            ->where('api_url', $state->apiUrl)
            ->where('app_id', $credentials->appId)
            ->exists();

        if ($exists) {
            throw DomainRuleViolation::conflict('This GitHub App is already connected.');
        }

        return DB::transaction(function () use ($state, $credentials, $user, $actor): GitHubApp {
            $app = new GitHubApp([
                'team_id' => $state->team->id,
                'app_id' => $credentials->appId,
                'slug' => $credentials->slug,
                'name' => $credentials->name,
                'owner_login' => $credentials->ownerLogin,
                'client_id' => $credentials->clientId,
                'web_url' => $state->webUrl,
                'api_url' => $state->apiUrl,
                // Never trust a link from the (possibly GHE) server as is (review L10).
                'html_url' => GitHubApp::safeHtmlUrl($credentials->htmlUrl, $state->webUrl, $credentials->slug),
                'created_by' => $user->id,
            ]);
            // The public id is part of the encryption context, so it exists
            // before the first save.
            $app->public_id = PublicId::generate(GitHubApp::publicIdPrefix());
            $this->secrets->seal($app, $credentials);
            $app->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::GitHubAppCreated,
                actor: $actor,
                teamId: $state->team->id,
                subjectType: 'github_app',
                subjectId: $app->public_id,
                meta: ['app_id' => $credentials->appId, 'slug' => $credentials->slug, 'api_url' => $state->apiUrl],
            ));

            return $app;
        });
    }
}
