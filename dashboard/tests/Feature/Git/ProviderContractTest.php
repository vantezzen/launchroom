<?php

declare(strict_types=1);

use App\Data\Git\RepositoryRef;
use App\Enums\Git\GitProviderKind;
use App\Exceptions\Git\RepositoryFileUnavailable;
use App\Models\Team;
use App\Services\Git\GitProviders;
use App\Services\Git\Providers\GitHub\GitHubProvider;
use App\Support\Git\PushedFiles;
use App\Support\Json\JsonObject;
use Illuminate\Support\Facades\Http;
use Tests\Support\GitHubFake;
use Tests\Support\GitProviderFake;

beforeEach(function (): void {
    GitHubFake::setUp();
    GitProviderFake::setUp();
    $this->team = Team::factory()->create();
});

test('only plain git servers can not read files', function (GitProviderKind $kind, bool $reads): void {
    expect(resolve(GitProviders::class)->for($kind)->canReadFiles())->toBe($reads);
})->with([
    [GitProviderKind::GitHub, true],
    [GitProviderKind::GitLab, true],
    [GitProviderKind::Gitea, true],
    [GitProviderKind::Git, false],
]);

test('GitHub reports an unusable repository as a file read failure, like every provider', function (): void {
    $installation = GitHubFake::installation(GitHubFake::app($this->team));
    GitHubFake::repository($installation);
    $installation->forceFill(['suspended_at' => now()])->save();
    $ref = new RepositoryRef(GitProviderKind::GitHub, $this->team->id, $installation->id, 555, 'octo-org/web');

    expect(fn () => resolve(GitHubProvider::class)->tree($ref, 'main'))->toThrow(RepositoryFileUnavailable::class)
        ->and(fn () => resolve(GitHubProvider::class)->fileAtRef($ref, 'compose.yaml', 'main'))->toThrow(RepositoryFileUnavailable::class);
});

test('GitHub request failures while listing files are file read failures', function (): void {
    $installation = GitHubFake::installation(GitHubFake::app($this->team));
    GitHubFake::repository($installation);
    Http::fake([
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse(),
        'https://api.github.com/repos/octo-org/web/git/trees/*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    expect(fn () => resolve(GitHubProvider::class)->tree(new RepositoryRef(GitProviderKind::GitHub, $this->team->id, $installation->id, 555, 'octo-org/web'), 'nope'))
        ->toThrow(RepositoryFileUnavailable::class);
});

test('file links are built on the connection’s own host', function (GitProviderKind $kind, string $suffix): void {
    $connection = GitProviderFake::connection($this->team, $kind);
    $repository = GitProviderFake::repository($connection);
    $sha = str_repeat('d', 40);

    expect(resolve(GitProviders::class)->for($kind)->fileUrl($repository->repositoryRef(), 'launchroom.json', $sha, 3))
        ->toBe($connection->origin().'/acme/web'.$suffix.$sha.'/launchroom.json#L3');
})->with([
    [GitProviderKind::GitLab, '/-/blob/'],
    [GitProviderKind::Gitea, '/src/commit/'],
]);

test('a push lists its changed files only while the payload holds every commit', function (): void {
    $commit = static fn (string $file): JsonObject => new JsonObject(['added' => [$file], 'modified' => [], 'removed' => []]);

    expect(PushedFiles::of([$commit('b'), $commit('a'), $commit('a')]))->toBe(['a', 'b'])
        ->and(PushedFiles::of([$commit('a')], 21))->toBeNull()
        ->and(PushedFiles::of(array_fill(0, PushedFiles::MAX_PAYLOAD_COMMITS, $commit('a'))))->toBeNull();
});
