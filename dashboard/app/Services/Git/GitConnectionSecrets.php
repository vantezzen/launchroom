<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Models\Git\GitConnection;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use App\Services\Crypto\SecretKey;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Envelope encryption for git connection credentials (docs/09 R13, docs/19
 * §3), the same scheme as GitHub Apps: a per-row data key wrapped by the
 * instance KEK with the connection's public id as context, every value bound
 * to `git_connection|team|connection|field` as associated data.
 */
final readonly class GitConnectionSecrets
{
    public const array FIELDS = ['oauth_client_secret', 'access_token', 'refresh_token', 'webhook_secret', 'ssh_private_key'];

    public function __construct(
        private Envelope $envelope,
    ) {}

    /**
     * Gives an unsaved connection (with team and public id) its data key and
     * a fresh webhook secret (32 random bytes, hex).
     */
    public function initialize(GitConnection $connection): void
    {
        $dek = $this->envelope->generateDataKey();
        $connection->wrapped_dek = $this->envelope->wrap($dek, $this->wrapContext($connection));
        $connection->kek_id = $this->envelope->kekId();
        $connection->webhook_secret = $dek->encrypt(bin2hex(random_bytes(32)), $this->associatedData($connection, 'webhook_secret'));
    }

    /**
     * Sets (or clears, with null) one secret field; the caller saves.
     */
    public function put(GitConnection $connection, string $field, #[SensitiveParameter] ?string $value): void
    {
        $this->assertField($field);

        $connection->setAttribute($field, $value === null || $value === '' ? null : $this->dataKey($connection)->encrypt($value, $this->associatedData($connection, $field)));
    }

    /**
     * @throws DecryptionFailed
     */
    public function get(GitConnection $connection, string $field): ?string
    {
        $this->assertField($field);
        $value = $connection->getAttribute($field);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $this->dataKey($connection)->decrypt($value, $this->associatedData($connection, $field));
    }

    /**
     * The webhook secret (never null: every connection has one).
     *
     * @throws DecryptionFailed
     */
    public function webhookSecret(GitConnection $connection): string
    {
        return (string) $this->get($connection, 'webhook_secret');
    }

    /**
     * Re-wraps the stored DEK from one KEK to another (rotate-kek); the caller saves.
     */
    public function rewrap(GitConnection $connection, Envelope $from, Envelope $to): void
    {
        $connection->wrapped_dek = $from->rewrap($connection->wrapped_dek, $this->wrapContext($connection), $to);
        $connection->kek_id = $to->kekId();
    }

    private function dataKey(GitConnection $connection): SecretKey
    {
        return $this->envelope->unwrap($connection->wrapped_dek, $this->wrapContext($connection));
    }

    private function assertField(string $field): void
    {
        if (! in_array($field, self::FIELDS, true)) {
            throw new InvalidArgumentException("{$field} is not a git connection secret.");
        }
    }

    private function wrapContext(GitConnection $connection): string
    {
        return 'git_connection:'.$connection->public_id;
    }

    private function associatedData(GitConnection $connection, string $field): string
    {
        return "git_connection|{$connection->team_id}|{$connection->public_id}|{$field}";
    }
}
