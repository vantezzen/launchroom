<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\Git\SshRemotes;
use App\Enums\Git\GitAuthMethod;
use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\GitProviderKind;
use App\Enums\Git\GitServerKind;
use App\Models\Git\GitConnection;
use App\Models\Git\GitRepository;
use App\Models\Team;
use App\Services\Git\Exceptions\GitConnectionUnusable;
use App\Services\Git\GitConnectionSecrets;
use App\Services\Git\Ssh\DeployKey;
use App\Support\Git\HostKey;
use App\Support\Git\SshUrl;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use SensitiveParameter;

/**
 * GitLab / Gitea / plain git test fixtures (docs/19): connections with real
 * envelope-encrypted secrets, repositories, signed webhook requests and a
 * scripted SSH client. Uses GitHubFake::setUp() (test KEK, public DNS, no
 * stray HTTP).
 */
final class GitProviderFake
{
    public const string GITLAB = 'https://gitlab.example.com';

    public const string GITEA = 'https://git.example.com';

    public const string TOKEN = 'glpat-test-token';

    /** A real ed25519 host key blob (`ssh-ed25519 AAAA…`), 32 zero-ish bytes are fine for parsing. */
    public static function hostKey(string $seed = 'host'): HostKey
    {
        $public = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(hash('sha256', $seed, true)));

        return HostKey::fromBlob(base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).$public)) ?? throw new \LogicException('bad key');
    }

    public static function setUp(): void
    {
        GitHubFake::setUp();
    }

    public static function connection(Team $team, GitProviderKind $provider = GitProviderKind::GitLab, GitConnectionStatus $status = GitConnectionStatus::Active, GitAuthMethod $method = GitAuthMethod::Token, int $accountId = 7): GitConnection
    {
        $secrets = resolve(GitConnectionSecrets::class);
        $base = match ($provider) {
            GitProviderKind::GitLab => self::GITLAB,
            GitProviderKind::Gitea => self::GITEA,
            default => 'ssh://git@git.example.com/srv/app.git',
        };

        $connection = new GitConnection([
            'team_id' => $team->id,
            'provider' => $provider,
            'server_kind' => match ($provider) {
                GitProviderKind::GitLab => GitServerKind::GitLab,
                GitProviderKind::Gitea => GitServerKind::Forgejo,
                default => GitServerKind::Git,
            },
            'base_url' => $base,
            'name' => parse_url($base, PHP_URL_HOST).' (ada)',
            'account_login' => $provider === GitProviderKind::Git ? null : 'ada',
            'account_id' => $provider === GitProviderKind::Git ? null : $accountId,
            'auth_method' => $provider === GitProviderKind::Git ? GitAuthMethod::DeployKey : $method,
            'oauth_client_id' => $method === GitAuthMethod::OAuth ? 'client-id' : null,
        ]);
        $connection->public_id = PublicId::generate('gic');
        $secrets->initialize($connection);

        if ($provider === GitProviderKind::Git) {
            $key = DeployKey::generate('test');
            $connection->ssh_public_key = $key['publicKey'];
            $connection->host_keys = [self::hostKey()->toArray()];
            $connection->host_keys_confirmed_at = now();
            $secrets->put($connection, 'ssh_private_key', $key['secretKey']);
        } else {
            $secrets->put($connection, 'access_token', self::TOKEN);

            if ($method === GitAuthMethod::OAuth) {
                $secrets->put($connection, 'refresh_token', 'refresh-1');
                $secrets->put($connection, 'oauth_client_secret', 'client-secret');
            }
        }

        $connection->save();

        if ($status !== GitConnectionStatus::Pending) {
            $connection->transitionTo(GitConnectionStatus::Active);
        }

        if ($status === GitConnectionStatus::NeedsReauth) {
            $connection->transitionTo(GitConnectionStatus::NeedsReauth);
        }

        return $connection->refresh();
    }

    public static function repository(GitConnection $connection, int $externalId = 42, string $fullName = 'acme/web'): GitRepository
    {
        $repository = GitRepository::query()->create([
            'team_id' => $connection->team_id,
            'git_connection_id' => $connection->id,
            'external_id' => $connection->provider === GitProviderKind::Git ? 0 : $externalId,
            'full_name' => $connection->provider === GitProviderKind::Git ? 'git.example.com/srv/app' : $fullName,
            'default_branch' => 'main',
            'private' => true,
            'archived' => false,
        ]);
        $repository->setRelation('gitConnection', $connection);

        return $repository;
    }

    public static function secret(GitConnection $connection): string
    {
        return resolve(GitConnectionSecrets::class)->webhookSecret($connection);
    }

    /**
     * POSTs a GitLab webhook with `X-Gitlab-Token` (null: no header).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function gitlabWebhook(TestCase $test, GitConnection $connection, string $event, array $payload, ?string $token = '', ?string $uuid = null): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return self::post($test, '/webhooks/gitlab/'.$connection->public_id, $body, [
            'X-Gitlab-Event' => $event,
            'X-Gitlab-Event-UUID' => $uuid ?? (string) Str::uuid(),
            'X-Gitlab-Token' => $token === '' ? self::secret($connection) : $token,
        ]);
    }

    /**
     * POSTs a Gitea/Forgejo webhook signed with `X-Gitea-Signature` (null: unsigned).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function giteaWebhook(TestCase $test, GitConnection $connection, string $event, array $payload, #[SensitiveParameter] ?string $secret = '', ?string $delivery = null, string $header = 'X-Gitea-Signature'): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $secret = $secret === '' ? self::secret($connection) : $secret;

        return self::post($test, '/webhooks/gitea/'.$connection->public_id, $body, [
            'X-Gitea-Event' => $event,
            'X-Gitea-Delivery' => $delivery ?? (string) Str::uuid(),
            $header => $secret === null ? null : hash_hmac('sha256', $body, $secret),
        ]);
    }

    /**
     * @param  array<string, string|null>  $headers
     */
    public static function post(TestCase $test, string $uri, string $body, array $headers): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            if ($value !== null) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
            }
        }

        return $test->call('POST', $uri, [], [], [], $server, $body);
    }

    /**
     * Binds an SSH client that answers from a script instead of the network.
     *
     * @param  list<HostKey>  $keys
     * @param  array<string, string>  $refs
     */
    public static function ssh(array $keys, array $refs = ['HEAD' => 'abc', 'refs/heads/main' => 'abc'], ?string $refuse = null): object
    {
        $fake = new class($keys, $refs, $refuse) implements SshRemotes
        {
            /** @var list<array{string, list<HostKey>}> */
            public array $refCalls = [];

            /**
             * @param  list<HostKey>  $keys
             * @param  array<string, string>  $refs
             */
            public function __construct(public array $keys, public array $refs, public ?string $refuse) {}

            public function hostKeys(SshUrl $url, bool $privateNetwork): array
            {
                return $this->keys;
            }

            public function refs(SshUrl $url, array $pinned, #[SensitiveParameter] string $secretKey, bool $privateNetwork): array
            {
                $this->refCalls[] = [$url->toUrl(), $pinned];

                if ($this->refuse !== null) {
                    throw new GitConnectionUnusable($this->refuse);
                }

                return $this->refs;
            }
        };

        app()->instance(SshRemotes::class, $fake);

        return $fake;
    }
}
