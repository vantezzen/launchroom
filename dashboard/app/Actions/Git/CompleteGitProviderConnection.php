<?php

declare(strict_types=1);

namespace App\Actions\Git;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Git\GitConnectionStatus;
use App\Enums\Permission;
use App\Exceptions\DomainRuleViolation;
use App\Models\Git\GitConnection;
use App\Models\Git\GitOAuthState;
use App\Models\User;
use App\Services\Git\GitConnectionAccounts;
use App\Services\Git\GitConnectionSecrets;
use App\Services\Git\GitConnectionTokens;
use App\Services\Git\GitProviderApi;
use App\Services\GitHub\JsonObject;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

/**
 * The OAuth callback (docs/19 §4.1): consumes the single-use state (bound to
 * the user who started it, unexpired), exchanges the code with the PKCE
 * verifier and the client secret, reads the account and activates the
 * connection. Signing in again to an existing account updates that
 * connection instead of creating a second one.
 */
final readonly class CompleteGitProviderConnection
{
    public function __construct(
        private GitConnectionSecrets $secrets,
        private GitConnectionTokens $tokens,
        private GitProviderApi $api,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public function handle(User $user, #[SensitiveParameter] string $state, #[SensitiveParameter] string $code, AuditActor $actor): GitConnection
    {
        $pending = DB::transaction(static function () use ($user, $state): ?GitOAuthState {
            $row = GitOAuthState::query()->with('gitConnection')->where('state_hash', hash('sha256', $state))->lockForUpdate()->first();
            $row?->delete();

            return $row instanceof GitOAuthState && $row->user_id === $user->id && $row->expires_at->isFuture() ? $row : null;
        });

        $connection = $pending?->gitConnection;

        if (! $pending instanceof GitOAuthState || ! $connection instanceof GitConnection || ! $user->hasTeamPermission($connection->team_id, Permission::ManageServers)) {
            throw DomainRuleViolation::on('git', 'This sign-in link expired or was already used. Start connecting again.');
        }

        $label = $connection->server_kind->label();

        try {
            $response = $this->tokens->client($connection)->postForm(GitConnectionTokens::tokenUrl($connection), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => route('git.oauth.callback'),
                'client_id' => (string) $connection->oauth_client_id,
                'client_secret' => (string) $this->secrets->get($connection, 'oauth_client_secret'),
                'code_verifier' => $pending->code_verifier,
            ], ['Accept' => 'application/json']);
            $tokens = $response->successful() ? JsonObject::decode($response->body()) : null;
        } catch (Throwable) {
            $tokens = null;
        }

        if (! $tokens instanceof JsonObject || $tokens->stringOrNull('access_token') === null) {
            throw DomainRuleViolation::on('git', "{$label} didn’t accept the sign-in. Check the application’s redirect URL and secret, then try again.");
        }

        $this->tokens->store($connection, $tokens);

        try {
            $account = JsonObject::decode($this->api->send($connection, 'GET', '/user', token: $tokens->string('access_token'))->body());
        } catch (Throwable) {
            throw DomainRuleViolation::on('git', "{$label} signed you in but didn’t return your account. Try again.");
        }

        GitConnectionAccounts::apply($connection, $account);

        return DB::transaction(function () use ($connection, $actor): GitConnection {
            $existing = GitConnectionAccounts::existing($connection);

            if ($existing instanceof GitConnection) {
                // Signing in again: keep the existing row (services point at it).
                foreach (['oauth_client_secret', 'access_token', 'refresh_token'] as $field) {
                    $this->secrets->put($existing, $field, $this->secrets->get($connection, $field));
                }

                $existing->forceFill(['oauth_client_id' => $connection->oauth_client_id, 'token_expires_at' => $connection->token_expires_at, 'auth_method' => $connection->auth_method]);
                $existing->transitionTo(GitConnectionStatus::Active);
                $connection->delete();
                $connection = $existing;
            } else {
                $connection->transitionTo(GitConnectionStatus::Active);
            }

            $this->audit->handle(new AuditEntryData(AuditAction::GitConnectionAuthorized, $actor, $connection->team_id, 'git_connection', $connection->public_id, [
                'provider' => $connection->provider->value,
                'account' => $connection->account_login,
            ]));

            return $connection;
        });
    }
}
