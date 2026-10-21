<?php

declare(strict_types=1);

use App\Actions\Git\GitCloneCredentials;
use App\Data\Services\GitSource;
use App\Exceptions\DomainRuleViolation;
use App\Models\Team;
use App\Services\GitHub\Exceptions\GitHubClockSkew;
use App\Services\GitHub\GitHubClientFactory;
use App\Services\GitHub\GitHubClock;
use App\Services\GitHub\TokenScope;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\GitHubFake;

beforeEach(function (): void {
    GitHubFake::setUp();
    $this->team = Team::factory()->create();
    $this->githubApp = GitHubFake::app($this->team);
    $this->installation = GitHubFake::installation($this->githubApp);
    $this->repository = GitHubFake::repository($this->installation, 555, 'octo-org/web');
});

test('clone credentials are a token scoped to one repository with read-only contents', function (): void {
    Http::fake(['https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse('ghs_clone')]);

    $credentials = resolve(GitCloneCredentials::class)->handle($this->team, new GitSource(
        repositoryId: 555,
        fullName: 'octo-org/web',
        installationId: $this->installation->id,
    ));

    expect($credentials->cloneUrl)->toBe('https://github.com/octo-org/web.git')
        ->and($credentials->token->reveal())->toBe('ghs_clone')
        ->and($credentials->token->repositoryIds)->toBe([555])
        ->and($credentials->authorizationHeader())->toBe('Authorization: Basic '.base64_encode('x-access-token:ghs_clone'))
        ->and(print_r($credentials, true))->not->toContain('ghs_clone')
        ->and(fn (): string => serialize($credentials))->toThrow(LogicException::class);

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'permissions' => ['contents' => 'read', 'metadata' => 'read'],
        'repository_ids' => [555],
    ] && str_starts_with($request->header('Authorization')[0] ?? '', 'Bearer ey'));
});

test('every build gets its own clone token: clone tokens are never cached or reused (Info: R19)', function (): void {
    Http::fake(['https://api.github.com/app/installations/100/access_tokens' => Http::sequence()
        ->pushResponse(GitHubFake::tokenResponse('ghs_build_1'))
        ->pushResponse(GitHubFake::tokenResponse('ghs_build_2')),
    ]);
    $source = new GitSource(repositoryId: 555, fullName: 'octo-org/web', installationId: $this->installation->id);

    expect(resolve(GitCloneCredentials::class)->handle($this->team, $source)->token->reveal())->toBe('ghs_build_1')
        ->and(resolve(GitCloneCredentials::class)->handle($this->team, $source)->token->reveal())->toBe('ghs_build_2');

    Http::assertSentCount(2);
});

test('clone credentials refuse installations of other teams and unknown repositories', function (GitSource $source): void {
    Http::fake();

    resolve(GitCloneCredentials::class)->handle($this->team, $source);
})->with([
    'other team' => fn (): GitSource => new GitSource(555, 'octo-org/web', GitHubFake::installation(GitHubFake::app(Team::factory()->create(), 99), 300)->id),
    'unknown repository' => fn (): GitSource => new GitSource(999, 'octo-org/other', $this->installation->id),
    'no installation' => fn (): GitSource => new GitSource(555, 'octo-org/web'),
])->throws(DomainRuleViolation::class);

test('tokens are cached per scope until shortly before they expire', function (): void {
    Http::fake(['https://api.github.com/app/installations/100/access_tokens' => Http::sequence()
        ->pushResponse(GitHubFake::tokenResponse('ghs_1'))
        ->pushResponse(GitHubFake::tokenResponse('ghs_2'))
        ->pushResponse(GitHubFake::tokenResponse('ghs_3')),
    ]);

    $client = resolve(GitHubClientFactory::class)->for($this->githubApp);

    expect($client->installationToken(100, TokenScope::clone(555))->reveal())->toBe('ghs_1')
        ->and($client->installationToken(100, TokenScope::clone(555))->reveal())->toBe('ghs_1')
        // Another repository or permission set is another token.
        ->and($client->installationToken(100, TokenScope::report(555))->reveal())->toBe('ghs_2');

    Http::assertSentCount(2);

    // 56 minutes later the cached token is within the refresh margin.
    $this->travel(56)->minutes();

    expect($client->installationToken(100, TokenScope::clone(555))->reveal())->toBe('ghs_3');
});

test('cached tokens are encrypted at rest', function (): void {
    Http::fake(['https://api.github.com/*' => GitHubFake::tokenResponse('ghs_cached_secret')]);

    resolve(GitHubClientFactory::class)->for($this->githubApp)->installationToken(100, TokenScope::clone(555));

    $store = cache()->getStore();
    $values = (fn (): array => $this->storage)->call($store);

    expect(json_encode($values))->not->toContain('ghs_cached_secret');
});

test('clock skew is diagnosed from the Date header without extra requests', function (): void {
    Log::spy();
    $githubTime = now()->addMinutes(5)->utc()->format('D, d M Y H:i:s \G\M\T');

    Http::fake(['https://api.github.com/app/installations/100' => Http::response(['message' => 'Bad credentials'], 401, ['Date' => $githubTime])]);

    $client = resolve(GitHubClientFactory::class)->for($this->githubApp);

    expect(fn () => $client->installation(100))->toThrow(GitHubClockSkew::class);
    expect(resolve(GitHubClock::class)->skew())->toBeGreaterThanOrEqual(299);

    Http::assertSentCount(1);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'Clock skew'))->once();
});

test('a known skew shifts JWT timestamps to GitHub’s clock', function (): void {
    resolve(GitHubClock::class)->observe(now()->addSeconds(120)->utc()->format('D, d M Y H:i:s \G\M\T'));

    $jwt = resolve(GitHubClientFactory::class)->for($this->githubApp)->jwt();
    $claims = json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true);

    expect($claims['iat'])->toBeGreaterThanOrEqual(now()->getTimestamp() + 120 - 60 - 2);
});
