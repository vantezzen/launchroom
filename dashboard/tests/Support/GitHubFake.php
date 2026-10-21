<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\DnsResolver;
use App\Enums\Git\RepositorySelection;
use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRepository;
use App\Models\Team;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\SecretKey;
use App\Services\GitHub\Dto\ManifestConversion;
use App\Services\GitHub\GitHubAppSecrets;
use App\Support\PublicId;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * GitHub test fixtures: a test KEK, public DNS for GitHub hosts, apps with
 * real (envelope-encrypted) credentials, and signed webhook requests. No test
 * ever reaches github.com: requests are faked and strays are refused.
 */
final class GitHubFake
{
    public const string API = 'https://api.github.com';

    public const string WEBHOOK_SECRET = 'whsec_test_secret';

    private static ?string $privateKey = null;

    public static function setUp(): void
    {
        app()->instance(Envelope::class, new Envelope(SecretKey::generate()));
        app()->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function resolve(string $hostname): array
            {
                return match (true) {
                    str_ends_with($hostname, 'internal.example.com') => ['10.0.0.5'],
                    default => ['140.82.112.6'],
                };
            }
        });

        // Stray requests fail the test; Inertia's dev SSR server would be one.
        config(['inertia.ssr.enabled' => false]);
        // Repositories have no launchroom.json unless a test says so (docs/18);
        // registered first, so it wins over a test's catch-all GitHub fake.
        Http::fake(['https://api.github.com/repos/*/contents/launchroom.json*' => Http::response(['message' => 'Not Found'], 404)]);
        Http::preventStrayRequests();
    }

    /**
     * A 2048-bit RSA key (GitHub's size), generated once per test run.
     */
    public static function privateKey(): string
    {
        if (self::$privateKey === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            assert($key !== false);
            openssl_pkey_export($key, $pem);
            self::$privateKey = (string) $pem;
        }

        return self::$privateKey;
    }

    public static function publicKey(): string
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private(self::privateKey()) ?: throw new \RuntimeException('bad key'));

        return (string) ($details['key'] ?? '');
    }

    public static function app(Team $team, int $appId = 4242, ?string $webhookSecret = self::WEBHOOK_SECRET, string $apiUrl = self::API): GitHubApp
    {
        $app = new GitHubApp([
            'team_id' => $team->id,
            'app_id' => $appId,
            'slug' => "launchroom-test-{$appId}",
            'name' => "launchroom-test-{$appId}",
            'owner_login' => 'octo-org',
            'client_id' => 'Iv1.0123456789abcdef',
            'web_url' => $apiUrl === self::API ? 'https://github.com' : rtrim(str_replace('/api/v3', '', $apiUrl), '/'),
            'api_url' => $apiUrl,
            'html_url' => "https://github.com/apps/launchroom-test-{$appId}",
        ]);
        $app->public_id = PublicId::generate('gha');

        resolve(GitHubAppSecrets::class)->seal($app, self::conversion($appId, $webhookSecret));
        $app->save();

        return $app;
    }

    public static function conversion(int $appId = 4242, ?string $webhookSecret = self::WEBHOOK_SECRET): ManifestConversion
    {
        return new ManifestConversion(
            appId: $appId,
            slug: "launchroom-test-{$appId}",
            name: "launchroom-test-{$appId}",
            clientId: 'Iv1.0123456789abcdef',
            htmlUrl: "https://github.com/apps/launchroom-test-{$appId}",
            ownerLogin: 'octo-org',
            clientSecret: 'client-secret-value',
            webhookSecret: $webhookSecret,
            privateKey: self::privateKey(),
        );
    }

    public static function installation(GitHubApp $app, int $installationId = 100, string $account = 'octo-org'): GitHubInstallation
    {
        return GitHubInstallation::query()->create([
            'team_id' => $app->team_id,
            'github_app_id' => $app->id,
            'installation_id' => $installationId,
            'account_id' => 9000 + $installationId,
            'account_login' => $account,
            'account_type' => 'Organization',
            'repository_selection' => RepositorySelection::Selected,
        ]);
    }

    public static function repository(GitHubInstallation $installation, int $repoId = 555, string $fullName = 'octo-org/web'): GitHubRepository
    {
        return GitHubRepository::query()->create([
            'team_id' => $installation->team_id,
            'github_installation_id' => $installation->id,
            'github_repo_id' => $repoId,
            'full_name' => $fullName,
            'default_branch' => 'main',
            'private' => true,
        ]);
    }

    public static function tokenResponse(string $token = 'ghs_test_token'): PromiseInterface
    {
        return Http::response([
            'token' => $token,
            'expires_at' => now()->addHour()->utc()->format('Y-m-d\TH:i:s\Z'),
            'permissions' => ['contents' => 'read', 'metadata' => 'read'],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    public static function installationJson(int $installationId = 100, int $appId = 4242, string $account = 'octo-org'): array
    {
        return [
            'id' => $installationId,
            'app_id' => $appId,
            'account' => ['id' => 9000 + $installationId, 'login' => $account, 'type' => 'Organization'],
            'repository_selection' => 'selected',
            'suspended_at' => null,
        ];
    }

    /**
     * POSTs a webhook signed over exactly `$body`.
     *
     * @param  array<string, mixed>|string  $payload
     * @param  array<string, string|null>  $headers  overrides; null removes a header
     */
    public static function webhook(TestCase $test, GitHubApp $app, string $event, array|string $payload, ?string $deliveryId = null, ?string $secret = self::WEBHOOK_SECRET, array $headers = []): TestResponse
    {
        $body = is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR);

        $all = [
            'X-GitHub-Event' => $event,
            'X-GitHub-Delivery' => $deliveryId ?? (string) Str::uuid(),
            'X-GitHub-Hook-Installation-Target-Type' => 'integration',
            'X-GitHub-Hook-Installation-Target-ID' => (string) $app->app_id,
            'X-Hub-Signature-256' => $secret === null ? null : 'sha256='.hash_hmac('sha256', $body, $secret),
            ...$headers,
        ];

        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($all as $name => $value) {
            if ($value !== null) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
            }
        }

        return $test->call('POST', '/webhooks/github', [], [], [], $server, $body);
    }
}
