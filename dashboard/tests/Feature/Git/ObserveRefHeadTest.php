<?php

declare(strict_types=1);

use App\Actions\Git\ObservePush;
use App\Actions\Git\ObserveRefHead;
use App\Data\Git\CommitData;
use App\Data\Git\PushPayload;
use App\Data\Git\RepositoryRef;
use App\Data\Services\GitSource;
use App\Enums\Git\GitEventSource;
use App\Enums\Git\GitProviderKind;
use App\Events\Git\GitPushReceived;
use App\Exceptions\GitHeadUnavailable;
use App\Models\Git\GitRefHead;
use App\Models\Team;
use App\Services\Git\GitHeads;
use App\Services\Git\GitProviders;
use App\Services\Git\Providers\UnconnectedGitProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\GitFixtures;
use Tests\Support\GitProviderFake;

/*
 * Every observed head, webhook or poll, of every provider goes through
 * ObserveRefHead (docs/06 §2, docs/19 §3).
 */

function orhCommit(string $sha, string $message = 'Ship it'): CommitData
{
    return new CommitData($sha, $message, null, null, null);
}

test('a connection head is emitted once, whichever channel sees it first', function (): void {
    Event::fake([GitPushReceived::class]);
    $connection = GitProviderFake::connection(Team::factory()->create());
    $observe = resolve(ObserveRefHead::class);
    $push = resolve(ObservePush::class);
    [$a, $b, $c] = [str_repeat('a', 40), str_repeat('b', 40), str_repeat('c', 40)];

    // Polling's first look is a baseline.
    expect($observe->handle($connection, 42, 'refs/heads/main', orhCommit($a), GitEventSource::Polling))->toBeFalse();

    // A webhook push emits; polling the same head afterwards doesn't.
    expect($push->handle($connection, new PushPayload(42, 'refs/heads/main', $a, $b, orhCommit($b), ['src/app.ts'], false)))->toBeTrue()
        ->and($observe->handle($connection, 42, 'refs/heads/main', orhCommit($b), GitEventSource::Polling))->toBeFalse();
    Event::assertDispatched(GitPushReceived::class, static fn (GitPushReceived $e): bool => $e->before === $a && $e->after === $b && $e->changedFiles === ['src/app.ts'] && $e->source === GitEventSource::Webhook);

    // Polling first: emitted with the recorded head as `before`, then waits for its webhook.
    expect($observe->handle($connection, 42, 'refs/heads/main', orhCommit($c), GitEventSource::Polling))->toBeTrue()
        ->and(GitRefHead::query()->sole()->awaiting_webhook_since)->not->toBeNull();
    Event::assertDispatched(GitPushReceived::class, static fn (GitPushReceived $e): bool => $e->before === $b && $e->after === $c && $e->source === GitEventSource::Polling);
    expect($push->handle($connection, new PushPayload(42, 'refs/heads/main', $b, $c, orhCommit($c), null, false)))->toBeFalse()
        ->and(GitRefHead::query()->sole()->awaiting_webhook_since)->toBeNull();

    // Skip markers and deletions are recorded, not emitted.
    expect($push->handle($connection, new PushPayload(42, 'refs/heads/main', $c, $a, orhCommit($a, 'Docs [skip ci]'), null, false)))->toBeFalse()
        ->and(GitRefHead::query()->sole()->head_sha)->toBe($a)
        ->and($push->handle($connection, new PushPayload(42, 'refs/heads/main', $a, str_repeat('0', 40), orhCommit(str_repeat('0', 40)), null, false)))->toBeFalse()
        ->and(GitRefHead::query()->sole()->head_sha)->toBeNull();
    Event::assertDispatchedTimes(GitPushReceived::class, 2);
});

test('an unconnected plain git source is its own provider, read through the safe client', function (): void {
    config(['launchroom.git.plain_sources' => true, 'launchroom.git.insecure_hosts' => ['git-fixtures']]);
    GitFixtures::fake(str_repeat('4', 40), 'trunk');
    $source = new GitSource(0, 'fixtures/node-hello', provider: GitProviderKind::Git, cloneUrl: 'http://git-fixtures:8080/node-hello.git', branch: 'trunk');
    $provider = resolve(GitProviders::class)->forSource($source);
    $ref = RepositoryRef::fromSource(1, $source);

    expect($provider)->toBeInstanceOf(UnconnectedGitProvider::class)
        ->and($provider->canReadFiles())->toBeFalse()
        ->and($provider->repositoryUrl($ref))->toBeNull()
        ->and($provider->cloneUrl($ref))->toBe('http://git-fixtures:8080/node-hello.git')
        ->and($provider->cloneCredentials($ref)->token)->toBeNull()
        ->and(resolve(GitHeads::class)->head(1, $source, 'trunk'))->toBe(str_repeat('4', 40));
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'http://git-fixtures:8080/node-hello.git/info/refs?service=git-upload-pack');

    expect(fn () => resolve(GitHeads::class)->head(1, $source, 'main'))->toThrow(GitHeadUnavailable::class, 'Branch main does not exist');

    // Outside local dev the source is refused before anything is fetched.
    config(['launchroom.git.plain_sources' => false]);
    expect(fn () => resolve(GitHeads::class)->head(1, $source, 'trunk'))->toThrow(GitHeadUnavailable::class, 'only available in local development');
});
