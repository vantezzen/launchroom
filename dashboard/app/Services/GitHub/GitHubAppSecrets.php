<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Models\Git\GitHubApp;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use App\Services\Crypto\SecretKey;
use App\Services\GitHub\Dto\ManifestConversion;

/**
 * Envelope encryption for GitHub App credentials (docs/09 R13: infrastructure
 * secrets use the same scheme as variables).
 *
 * Key choice: each app row gets its own data key, wrapped by the instance KEK
 * (`LAUNCHROOM_KEK`) with the app's public id as context. There is no project
 * here to borrow a project DEK from, and a per-row key keeps rotation simple:
 * rotating the KEK only rewraps `wrapped_dek`. Each value is bound to
 * `github_app|team|app|field` as associated data, so ciphertexts can't be
 * swapped between apps, teams or fields.
 */
final readonly class GitHubAppSecrets
{
    public function __construct(
        private Envelope $envelope,
    ) {}

    /**
     * Encrypts the credentials onto the (unsaved or saved) model. The model
     * must already have its team and public id.
     */
    public function seal(GitHubApp $app, ManifestConversion $credentials): void
    {
        $dek = $this->envelope->generateDataKey();

        $app->wrapped_dek = $this->envelope->wrap($dek, $this->wrapContext($app));
        $app->kek_id = $this->envelope->kekId();
        $app->client_secret = $dek->encrypt($credentials->clientSecret, $this->associatedData($app, 'client_secret'));
        $app->private_key = $dek->encrypt($credentials->privateKey, $this->associatedData($app, 'private_key'));
        $app->webhook_secret = $credentials->webhookSecret === null || $credentials->webhookSecret === ''
            ? null
            : $dek->encrypt($credentials->webhookSecret, $this->associatedData($app, 'webhook_secret'));
    }

    /**
     * @throws DecryptionFailed
     */
    public function privateKey(GitHubApp $app): string
    {
        return $this->dataKey($app)->decrypt($app->private_key, $this->associatedData($app, 'private_key'));
    }

    /**
     * The webhook secret, or null when the app has none (deliveries are then
     * rejected: fail closed, docs/09 R17).
     *
     * @throws DecryptionFailed
     */
    public function webhookSecret(GitHubApp $app): ?string
    {
        if ($app->webhook_secret === null || $app->webhook_secret === '') {
            return null;
        }

        return $this->dataKey($app)->decrypt($app->webhook_secret, $this->associatedData($app, 'webhook_secret'));
    }

    /**
     * @throws DecryptionFailed
     */
    public function clientSecret(GitHubApp $app): string
    {
        return $this->dataKey($app)->decrypt($app->client_secret, $this->associatedData($app, 'client_secret'));
    }

    private function dataKey(GitHubApp $app): SecretKey
    {
        return $this->envelope->unwrap($app->wrapped_dek, $this->wrapContext($app));
    }

    /**
     * Re-wraps the stored DEK from one KEK to another (rotate-kek); the caller saves.
     */
    public function rewrap(GitHubApp $app, Envelope $from, Envelope $to): void
    {
        $app->wrapped_dek = $from->rewrap($app->wrapped_dek, $this->wrapContext($app), $to);
        $app->kek_id = $to->kekId();
    }

    private function wrapContext(GitHubApp $app): string
    {
        return 'github_app:'.$app->public_id;
    }

    private function associatedData(GitHubApp $app, string $field): string
    {
        return "github_app|{$app->team_id}|{$app->public_id}|{$field}";
    }
}
