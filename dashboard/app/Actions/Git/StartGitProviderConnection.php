<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Git\ConnectGitProviderData;
use App\Enums\AuditAction;
use App\Enums\Git\GitAuthMethod;
use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\GitProviderKind;
use App\Exceptions\DomainRuleViolation;
use App\Models\Git\GitConnection;
use App\Models\Git\GitOAuthState;
use App\Models\Team;
use App\Models\User;
use App\Services\Crypto\SealedColumns;
use App\Services\Git\GitConnectionAccounts;
use App\Services\Git\GitProviderApi;
use App\Services\Git\GitProviders;
use App\Services\Git\GitServerProbe;
use App\Support\Json\JsonObject;
use App\Support\PublicId;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Connects GitLab or Gitea/Forgejo (docs/19 §4.1):
 *
 * - OAuth: stores a pending connection with the application's credentials
 *   and a single-use state + PKCE verifier, and returns the provider's
 *   authorize URL for the browser ({@see CompleteGitProviderConnection}
 *   finishes it);
 * - access token: checks it with `GET /user` and stores an active
 *   connection right away (returns null).
 *
 * A private-network address needs an instance owner (docs/09 R5, audited).
 */
final readonly class StartGitProviderConnection
{
    public function __construct(
        private GitServerProbe $probe,
        private SealedColumns $sealed,
        private GitProviderApi $api,
        private RecordAuditEvent $audit,
        private GitProviders $providers,
    ) {}

    /**
     * @return string|null the authorize URL (OAuth), or null when the token connection is active
     *
     * @throws DomainRuleViolation
     */
    public function handle(Team $team, User $user, ConnectGitProviderData $data, AuditActor $actor): ?string
    {
        if ($data->allowPrivateNetwork && ! $user->is_instance_owner) {
            throw DomainRuleViolation::on('allowPrivateNetwork', 'Only an instance owner can allow a server on a private network (docs/09 R5).');
        }

        $provider = GitProviderKind::from($data->provider);
        [$origin, $kind] = $this->probe->probe($provider, $data->baseUrl, $data->allowPrivateNetwork);
        $oauth = $data->method === 'oauth';

        if ($oauth && ($data->clientId === null || $data->clientSecret === null || $data->clientSecret === '')) {
            throw DomainRuleViolation::on('clientId', 'Paste the application ID and secret from '.$kind->label().'.');
        }

        if (! $oauth && ($data->token === null || trim($data->token) === '' || preg_match('/[\s\x00-\x1F]/', trim($data->token)) === 1)) {
            throw DomainRuleViolation::on('token', 'Paste an access token.');
        }

        $connection = new GitConnection([
            'team_id' => $team->id,
            'provider' => $provider,
            'server_kind' => $kind,
            'base_url' => $origin,
            'name' => (string) parse_url($origin, PHP_URL_HOST),
            'auth_method' => $oauth ? GitAuthMethod::OAuth : GitAuthMethod::Token,
            'oauth_client_id' => $oauth ? $data->clientId : null,
            'allow_private_network' => $data->allowPrivateNetwork,
            'created_by' => $user->id,
        ]);
        $connection->public_id = PublicId::generate(GitConnection::publicIdPrefix());
        $this->sealed->seal($connection, 'webhook_secret', bin2hex(random_bytes(32)), $this->sealed->newKey($connection));

        if ($oauth) {
            $this->sealed->seal($connection, 'oauth_client_secret', $data->clientSecret);

            return DB::transaction(function () use ($connection, $user, $actor): string {
                $connection->save();
                $this->audited($connection, $actor, AuditAction::GitConnectionCreated);

                $state = bin2hex(random_bytes(32));
                $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
                GitOAuthState::query()->create([
                    'state_hash' => hash('sha256', $state),
                    'git_connection_id' => $connection->id,
                    'user_id' => $user->id,
                    'code_verifier' => $verifier,
                    'expires_at' => now()->addMinutes(config()->integer('launchroom.git.oauth_state_ttl_minutes')),
                ]);

                $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

                return $this->providers->api($connection->provider)->oauthAuthorizeUrl($connection, [
                    'client_id' => (string) $connection->oauth_client_id,
                    'redirect_uri' => route('git.oauth.callback'),
                    'response_type' => 'code',
                    'state' => $state,
                    'code_challenge' => $challenge,
                    'code_challenge_method' => 'S256',
                ]);
            });
        }

        $token = trim((string) $data->token);

        try {
            $account = JsonObject::decode($this->api->send($connection, 'GET', '/user', token: $token)->body());
        } catch (Throwable) {
            throw DomainRuleViolation::on('token', "{$kind->label()} didn’t accept this token. Check its scopes (GitLab: api; Gitea: write:repository and read:user).");
        }

        $this->sealed->seal($connection, 'access_token', $token);
        $this->providers->api($connection->provider)->applyAccount($connection, $account);

        DB::transaction(function () use ($connection, $actor): void {
            $existing = GitConnectionAccounts::existing($connection);

            if ($existing instanceof GitConnection) {
                throw DomainRuleViolation::on('token', "{$connection->name} is already connected as {$connection->account_login}.");
            }

            $connection->save();
            $connection->transitionTo(GitConnectionStatus::Active);
            $this->audited($connection, $actor, AuditAction::GitConnectionCreated);
        });

        return null;
    }

    private function audited(GitConnection $connection, AuditActor $actor, AuditAction $action): void
    {
        $this->audit->handle(new AuditEntryData($action, $actor, $connection->team_id, 'git_connection', $connection->public_id, [
            'provider' => $connection->provider->value,
            'server' => $connection->base_url,
            'method' => $connection->auth_method->value,
        ]));

        if ($connection->allow_private_network) {
            $this->audit->handle(new AuditEntryData(AuditAction::GitConnectionPrivateNetworkAllowed, $actor, $connection->team_id, 'git_connection', $connection->public_id, ['server' => $connection->base_url]));
        }
    }
}
