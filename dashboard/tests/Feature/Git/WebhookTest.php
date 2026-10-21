<?php

declare(strict_types=1);

use App\Enums\Git\GitEventSource;
use App\Enums\Git\WebhookDeliveryStatus;
use App\Enums\TeamRole;
use App\Events\Git\GitPushReceived;
use App\Events\Git\PullRequestEventReceived;
use App\Jobs\Git\RefreshInstallationRepositoriesJob;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRepository;
use App\Models\Git\WebhookDelivery;
use App\Models\Team;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitHubFake;

beforeEach(function (): void {
    GitHubFake::setUp();
    Event::fake([GitPushReceived::class, PullRequestEventReceived::class]);

    $this->team = Team::factory()->create();
    memberOf($this->team, TeamRole::Owner);
    $this->githubApp = GitHubFake::app($this->team);
    $this->installation = GitHubFake::installation($this->githubApp);
    GitHubFake::repository($this->installation);
});

/**
 * @return array<string, mixed>
 */
function ghPushPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'ref' => 'refs/heads/main',
        'before' => str_repeat('a', 40),
        'after' => str_repeat('b', 40),
        'deleted' => false,
        'forced' => false,
        'installation' => ['id' => 100],
        'repository' => ['id' => 555, 'full_name' => 'octo-org/web', 'private' => true, 'default_branch' => 'main', 'pushed_at' => 1760781600],
        'head_commit' => [
            'id' => str_repeat('b', 40),
            'message' => 'Add pricing page',
            'author' => ['name' => 'Octo Cat', 'email' => 'octo@example.com', 'username' => 'octocat'],
        ],
        'commits' => [
            ['added' => ['pages/pricing.tsx'], 'modified' => ['package.json'], 'removed' => []],
            ['added' => [], 'modified' => ['package.json'], 'removed' => ['old.txt']],
        ],
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function ghPullRequestPayload(?int $headRepoId = 555, string $action = 'opened'): array
{
    return [
        'action' => $action,
        'number' => 42,
        'installation' => ['id' => 100],
        'repository' => ['id' => 555, 'full_name' => 'octo-org/web', 'private' => true],
        'pull_request' => [
            'title' => 'Add pricing',
            'draft' => false,
            'merged' => false,
            'user' => ['login' => 'contributor'],
            'labels' => [['name' => 'preview']],
            'head' => [
                'sha' => str_repeat('c', 40),
                'ref' => 'feature/pricing',
                // Same owner/name as the base is irrelevant: only ids count.
                'repo' => $headRepoId === null ? null : ['id' => $headRepoId, 'full_name' => 'octo-org/web'],
            ],
            'base' => ['ref' => 'main', 'repo' => ['id' => 555, 'full_name' => 'octo-org/web']],
        ],
    ];
}

test('a signed push is accepted with 202 and emits GitPushReceived', function (): void {
    GitHubFake::webhook($this, $this->githubApp, 'push', ghPushPayload(), 'delivery-1')
        ->assertStatus(202)
        ->assertJson(['status' => 'accepted']);

    Event::assertDispatchedTimes(GitPushReceived::class, 1);
    Event::assertDispatched(GitPushReceived::class, fn (GitPushReceived $event): bool => $event->installation->is($this->installation)
        && $event->repositoryId === 555
        && $event->ref === 'refs/heads/main'
        && $event->before === str_repeat('a', 40)
        && $event->after === str_repeat('b', 40)
        && $event->headCommit->message === 'Add pricing page'
        && $event->headCommit->authorLogin === 'octocat'
        && $event->changedFiles === ['old.txt', 'package.json', 'pages/pricing.tsx']
        && $event->source === GitEventSource::Webhook);

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->delivery_id)->toBe('delivery-1')
        ->and($delivery->event)->toBe('push')
        ->and($delivery->repository_id)->toBe(555)
        ->and($delivery->team_id)->toBe($this->team->id)
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Processed)
        ->and($this->githubApp->refresh()->webhook_last_received_at)->not->toBeNull()
        ->and($this->githubApp->hasHealthyWebhooks())->toBeTrue();
});

test('verification fails closed with 401', function (array $headers, ?string $secret): void {
    GitHubFake::webhook($this, $this->githubApp, 'push', ghPushPayload(), secret: $secret, headers: $headers)
        ->assertStatus(401);

    Event::assertNotDispatched(GitPushReceived::class);
    expect(WebhookDelivery::query()->count())->toBe(0)
        ->and($this->githubApp->refresh()->webhook_last_received_at)->toBeNull();
})->with([
    'wrong secret' => [[], 'not-the-secret'],
    'missing signature' => [[], null],
    'empty signature' => [['X-Hub-Signature-256' => ''], GitHubFake::WEBHOOK_SECRET],
    'sha1 only' => [['X-Hub-Signature-256' => null, 'X-Hub-Signature' => 'sha1=abc'], GitHubFake::WEBHOOK_SECRET],
    'unknown app' => [['X-GitHub-Hook-Installation-Target-ID' => '1'], GitHubFake::WEBHOOK_SECRET],
    'missing target' => [['X-GitHub-Hook-Installation-Target-ID' => null], GitHubFake::WEBHOOK_SECRET],
    'repository hook target' => [['X-GitHub-Hook-Installation-Target-Type' => 'repository'], GitHubFake::WEBHOOK_SECRET],
]);

test('an app without a webhook secret rejects everything, even an HMAC with an empty key', function (): void {
    $team = Team::factory()->create();
    $app = GitHubFake::app($team, 5151, webhookSecret: null);

    GitHubFake::webhook($this, $app, 'push', ghPushPayload(), secret: '')->assertStatus(401);
    GitHubFake::webhook($this, $app, 'push', ghPushPayload(), secret: null)->assertStatus(401);
});

test('the signature is checked over the raw body, not re-encoded JSON', function (): void {
    // Escaped unicode and slashes: json_encode(json_decode()) produces other bytes.
    $raw = str_replace('"Add pricing page"', '"Café \/ pricing"', json_encode(ghPushPayload(), JSON_UNESCAPED_SLASHES));
    $reencoded = json_encode(json_decode($raw, true));
    expect($reencoded)->not->toBe($raw);

    // Signed over the raw bytes: accepted.
    GitHubFake::webhook($this, $this->githubApp, 'push', $raw)->assertStatus(202);

    // Signed over the re-encoded JSON but sending the raw bytes: rejected.
    GitHubFake::webhook($this, $this->githubApp, 'push', $raw, headers: [
        'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', (string) $reencoded, GitHubFake::WEBHOOK_SECRET),
    ])->assertStatus(401);

    Event::assertDispatched(GitPushReceived::class, fn (GitPushReceived $event): bool => $event->headCommit->message === 'Café / pricing');
});

test('a replayed delivery is acknowledged but not processed again', function (): void {
    GitHubFake::webhook($this, $this->githubApp, 'push', ghPushPayload(), 'delivery-1')->assertStatus(202);
    GitHubFake::webhook($this, $this->githubApp, 'push', ghPushPayload(['after' => str_repeat('d', 40), 'head_commit' => ['id' => str_repeat('d', 40)]]), 'delivery-1')
        ->assertStatus(202)
        ->assertJson(['status' => 'duplicate']);

    Event::assertDispatchedTimes(GitPushReceived::class, 1);
    expect(WebhookDelivery::query()->count())->toBe(1);
});

test('malformed deliveries are rejected after verification', function (): void {
    GitHubFake::webhook($this, $this->githubApp, 'push', 'not json')->assertStatus(400);
    GitHubFake::webhook($this, $this->githubApp, 'push', ghPushPayload(), 'bad id with spaces')->assertStatus(400);
    GitHubFake::webhook($this, $this->githubApp, 'Push;id', ghPushPayload())->assertStatus(400);
});

test('ping is acknowledged and ignored', function (): void {
    GitHubFake::webhook($this, $this->githubApp, 'ping', ['zen' => 'Design for failure.', 'hook_id' => 1])->assertStatus(202);

    expect(WebhookDelivery::query()->sole()->status)->toBe(WebhookDeliveryStatus::Ignored);
});

test('skip markers and deleted refs do not emit pushes', function (array $overrides): void {
    GitHubFake::webhook($this, $this->githubApp, 'push', ghPushPayload($overrides))->assertStatus(202);

    Event::assertNotDispatched(GitPushReceived::class);
    expect(WebhookDelivery::query()->sole()->status)->toBe(WebhookDeliveryStatus::Ignored);
})->with([
    '[skip ci]' => [['head_commit' => ['message' => "Docs only\n\n[skip ci]"]]],
    '[skip deploy]' => [['head_commit' => ['message' => 'Tweak [Skip Deploy]']]],
    'deleted branch' => [['deleted' => true, 'after' => str_repeat('0', 40), 'head_commit' => null]],
    'invalid ref' => [['ref' => 'refs/heads/../x']],
]);

test('a push with more commits than the payload carries has unknown changed files', function (): void {
    GitHubFake::webhook($this, $this->githubApp, 'push', ghPushPayload(['commits' => array_fill(0, 20, ['added' => ['a'], 'modified' => [], 'removed' => []])]))->assertStatus(202);

    Event::assertDispatched(GitPushReceived::class, fn (GitPushReceived $event): bool => $event->changedFiles === null);
});

test('a push for an installation of another team is ignored', function (): void {
    $otherApp = GitHubFake::app(Team::factory()->create(), 7777);
    GitHubFake::installation($otherApp, 300);

    // Our app's delivery naming the other team's installation: GitHub doesn't
    // confirm it for our app (404), so nothing maps.
    Http::fake(['https://api.github.com/app/installations/300' => Http::response(['message' => 'Not Found'], 404)]);

    GitHubFake::webhook($this, $this->githubApp, 'push', ghPushPayload(['installation' => ['id' => 300]]))->assertStatus(202);

    Event::assertNotDispatched(GitPushReceived::class);
    expect(WebhookDelivery::query()->sole()->status)->toBe(WebhookDeliveryStatus::Ignored)
        ->and(GitHubInstallation::query()->where('installation_id', 300)->sole()->github_app_id)->toBe($otherApp->id);
});

test('the same repository in two teams maps only to the team whose app delivered it', function (): void {
    $otherApp = GitHubFake::app(Team::factory()->create(), 7777);
    $otherInstallation = GitHubFake::installation($otherApp, 300);
    GitHubFake::repository($otherInstallation, 555, 'octo-org/web');

    GitHubFake::webhook($this, $otherApp, 'push', ghPushPayload(['installation' => ['id' => 300]]))->assertStatus(202);

    Event::assertDispatched(GitPushReceived::class, fn (GitPushReceived $event): bool => $event->installation->is($otherInstallation)
        && $event->installation->team_id === $otherApp->team_id);
    Event::assertNotDispatched(GitPushReceived::class, fn (GitPushReceived $event): bool => $event->installation->is($this->installation));
});

test('a removed installation receives nothing', function (): void {
    $this->installation->forceFill(['removed_at' => now()])->save();

    GitHubFake::webhook($this, $this->githubApp, 'push', ghPushPayload())->assertStatus(202);

    Event::assertNotDispatched(GitPushReceived::class);
});

test('pull requests from the same repository are not forks', function (): void {
    GitHubFake::webhook($this, $this->githubApp, 'pull_request', ghPullRequestPayload(555))->assertStatus(202);

    Event::assertDispatched(PullRequestEventReceived::class, fn (PullRequestEventReceived $event): bool => $event->number === 42
        && $event->action === 'opened'
        && $event->headSha === str_repeat('c', 40)
        && $event->headRef === 'feature/pricing'
        && $event->baseRef === 'main'
        && $event->headRepoId === 555
        && $event->baseRepoId === 555
        && ! $event->isFork
        && $event->authorPermission === null
        && $event->labels === ['preview']);
});

test('fork detection compares repository ids and looks up the author’s permission', function (): void {
    Http::fake([
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse(),
        'https://api.github.com/repos/octo-org/web/collaborators/contributor/permission' => Http::response(['permission' => 'read']),
    ]);

    GitHubFake::webhook($this, $this->githubApp, 'pull_request', ghPullRequestPayload(999))->assertStatus(202);

    Event::assertDispatched(PullRequestEventReceived::class, fn (PullRequestEventReceived $event): bool => $event->isFork
        && $event->headRepoId === 999
        && $event->authorPermission === 'read');
});

test('a failed permission lookup fails closed: the author counts as without write access', function (): void {
    Http::fake([
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse(),
        'https://api.github.com/repos/octo-org/web/collaborators/contributor/permission' => Http::response(['message' => 'Server Error'], 502),
    ]);

    GitHubFake::webhook($this, $this->githubApp, 'pull_request', ghPullRequestPayload(999, 'synchronize'))->assertStatus(202);

    Event::assertDispatched(PullRequestEventReceived::class, fn (PullRequestEventReceived $event): bool => $event->isFork
        && $event->authorPermission === 'unknown');
});

test('a pull request from a deleted fork counts as a fork', function (): void {
    Http::fake([
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse(),
        'https://api.github.com/repos/*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    GitHubFake::webhook($this, $this->githubApp, 'pull_request', ghPullRequestPayload(null, 'synchronize'))->assertStatus(202);

    Event::assertDispatched(PullRequestEventReceived::class, fn (PullRequestEventReceived $event): bool => $event->isFork
        && $event->headRepoId === null
        && $event->authorPermission === 'none');
});

test('installation events keep installations and the repository cache in sync', function (): void {
    Http::fake([
        'https://api.github.com/app/installations/200/access_tokens' => GitHubFake::tokenResponse(),
        'https://api.github.com/installation/repositories*' => Http::response(['total_count' => 1, 'repositories' => [
            ['id' => 700, 'full_name' => 'acme/site', 'private' => true, 'default_branch' => 'main'],
        ]]),
    ]);

    GitHubFake::webhook($this, $this->githubApp, 'installation', [
        'action' => 'created',
        'installation' => GitHubFake::installationJson(200, account: 'acme'),
        'repositories' => [['id' => 700, 'full_name' => 'acme/site', 'private' => true]],
    ])->assertStatus(202);

    $installation = GitHubInstallation::query()->where('installation_id', 200)->sole();
    expect($installation->team_id)->toBe($this->team->id)
        ->and($installation->repositories()->sole()->default_branch)->toBe('main');

    GitHubFake::webhook($this, $this->githubApp, 'installation_repositories', [
        'action' => 'added',
        'installation' => GitHubFake::installationJson(200, account: 'acme'),
        'repository_selection' => 'selected',
        'repositories_added' => [['id' => 701, 'full_name' => 'acme/api', 'private' => false]],
        'repositories_removed' => [['id' => 700, 'full_name' => 'acme/site']],
    ])->assertStatus(202);

    // The refresh after the event re-reads GitHub's list (700 only, per the fake).
    expect($installation->repositories()->pluck('github_repo_id')->all())->toBe([700]);

    GitHubFake::webhook($this, $this->githubApp, 'installation', [
        'action' => 'deleted',
        'installation' => GitHubFake::installationJson(200, account: 'acme'),
    ])->assertStatus(202);

    expect($installation->refresh()->removed_at)->not->toBeNull()
        ->and($installation->repositories()->count())->toBe(0);
});

test('installation_repositories changes apply before the refresh runs', function (): void {
    Queue::fake([RefreshInstallationRepositoriesJob::class]);

    GitHubFake::webhook($this, $this->githubApp, 'installation_repositories', [
        'action' => 'removed',
        'installation' => GitHubFake::installationJson(100),
        'repository_selection' => 'selected',
        'repositories_added' => [['id' => 560, 'full_name' => 'octo-org/docs', 'private' => false]],
        'repositories_removed' => [['id' => 555, 'full_name' => 'octo-org/web']],
    ])->assertStatus(202);

    expect(GitHubRepository::query()->pluck('github_repo_id')->all())->toBe([560]);
    Queue::assertPushed(RefreshInstallationRepositoriesJob::class, fn (RefreshInstallationRepositoriesJob $job): bool => $job->installationId === $this->installation->id);
});

test('installation events for another app are ignored', function (): void {
    GitHubFake::webhook($this, $this->githubApp, 'installation', [
        'action' => 'created',
        'installation' => GitHubFake::installationJson(200, appId: 1),
    ])->assertStatus(202);

    expect(GitHubInstallation::query()->where('installation_id', 200)->exists())->toBeFalse();
});
