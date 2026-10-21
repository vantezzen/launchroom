<?php

declare(strict_types=1);

use App\Contracts\Git\TrackedRefs;
use App\Data\Git\TrackedRef;
use App\Enums\Git\GitEventSource;
use App\Events\Git\GitPushReceived;
use App\Jobs\Git\PollTrackedRefsJob;
use App\Models\Git\GitHubRefHead;
use App\Models\Team;
use App\Services\GitHub\NullTrackedRefs;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\GitHubFake;

beforeEach(function (): void {
    GitHubFake::setUp();
    Event::fake([GitPushReceived::class]);

    $this->githubApp = GitHubFake::app(Team::factory()->create());
    $this->installation = GitHubFake::installation($this->githubApp);
    GitHubFake::repository($this->installation, 555, 'octo-org/web');

    $refs = [new TrackedRef($this->installation->id, 555, 'refs/heads/main')];
    app()->instance(TrackedRefs::class, new readonly class($refs) implements TrackedRefs
    {
        /** @param list<TrackedRef> $refs */
        public function __construct(private array $refs) {}

        public function all(): iterable
        {
            // Duplicates are polled once.
            return [...$this->refs, ...$this->refs];
        }
    });
});

function ghCommitResponse(string $sha, string $etag, string $message = 'Ship it'): PromiseInterface
{
    return Http::response([
        'sha' => $sha,
        'commit' => ['message' => $message, 'author' => ['name' => 'Octo Cat', 'email' => 'octo@example.com']],
        'author' => ['login' => 'octocat'],
    ], 200, ['ETag' => $etag]);
}

test('the null implementation tracks nothing', function (): void {
    expect(iterator_to_array((new NullTrackedRefs)->all()))->toBe([]);
});

test('the first poll records a baseline, later head changes emit a push', function (): void {
    Http::fake([
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse(),
        'https://api.github.com/repos/octo-org/web/commits/main' => Http::sequence()
            ->pushResponse(ghCommitResponse(str_repeat('a', 40), '"etag-1"'))
            ->push(status: 304)
            ->pushResponse(ghCommitResponse(str_repeat('b', 40), '"etag-2"', 'Add pricing')),
    ]);

    dispatch_sync(new PollTrackedRefsJob);
    Event::assertNotDispatched(GitPushReceived::class);

    dispatch_sync(new PollTrackedRefsJob);
    Event::assertNotDispatched(GitPushReceived::class);

    dispatch_sync(new PollTrackedRefsJob);
    Event::assertDispatchedTimes(GitPushReceived::class, 1);
    Event::assertDispatched(GitPushReceived::class, fn (GitPushReceived $event): bool => $event->source === GitEventSource::Polling
        && $event->before === str_repeat('a', 40)
        && $event->after === str_repeat('b', 40)
        && $event->headCommit->message === 'Add pricing'
        && $event->headCommit->authorLogin === 'octocat'
        && $event->changedFiles === null);

    // Conditional requests with the stored ETag (a 304 costs no rate limit).
    $commitRequests = collect(Http::recorded())->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => str_contains($request->url(), '/commits/'))->values();

    expect($commitRequests)->toHaveCount(3)
        ->and($commitRequests[0]->hasHeader('If-None-Match'))->toBeFalse()
        ->and($commitRequests[1]->header('If-None-Match'))->toBe(['"etag-1"'])
        ->and($commitRequests[2]->header('If-None-Match'))->toBe(['"etag-1"'])
        ->and(GitHubRefHead::query()->sole()->etag)->toBe('"etag-2"');

    // The polling token reads one repository.
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request->data() === ['permissions' => ['contents' => 'read', 'metadata' => 'read'], 'repository_ids' => [555]]);
});

test('a head the webhook already delivered is not emitted again', function (): void {
    Http::fake([
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse(),
        'https://api.github.com/repos/octo-org/web/commits/main' => ghCommitResponse(str_repeat('b', 40), '"etag-2"'),
    ]);

    GitHubRefHead::query()->create([
        'github_installation_id' => $this->installation->id,
        'github_repo_id' => 555,
        'ref' => 'refs/heads/main',
        'head_sha' => str_repeat('b', 40),
    ]);

    dispatch_sync(new PollTrackedRefsJob);

    Event::assertNotDispatched(GitPushReceived::class);
});

test('skip markers apply to polled commits too', function (): void {
    Http::fake([
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse(),
        'https://api.github.com/repos/octo-org/web/commits/main' => ghCommitResponse(str_repeat('b', 40), '"e"', 'Docs [skip deploy]'),
    ]);
    GitHubRefHead::query()->create(['github_installation_id' => $this->installation->id, 'github_repo_id' => 555, 'ref' => 'refs/heads/main', 'head_sha' => str_repeat('a', 40)]);

    dispatch_sync(new PollTrackedRefsJob);

    Event::assertNotDispatched(GitPushReceived::class);
});

test('apps with healthy webhooks are not polled', function (): void {
    Http::fake();
    $this->githubApp->forceFill(['webhook_last_received_at' => now()->subMinute()])->save();

    dispatch_sync(new PollTrackedRefsJob);

    Http::assertNothingSent();
});

test('stale webhooks fall back to polling', function (): void {
    Http::fake([
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse(),
        'https://api.github.com/repos/octo-org/web/commits/main' => Http::response(status: 304),
    ]);
    $this->githubApp->forceFill(['webhook_last_received_at' => now()->subHour()])->save();

    dispatch_sync(new PollTrackedRefsJob);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/commits/main'));
});

test('one failing ref does not stop polling', function (): void {
    Http::fake([
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse(),
        'https://api.github.com/repos/octo-org/web/commits/main' => Http::response(['message' => 'boom'], 500),
    ]);

    dispatch_sync(new PollTrackedRefsJob);

    Event::assertNotDispatched(GitPushReceived::class);
});
