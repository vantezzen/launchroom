<?php

declare(strict_types=1);

use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\GitEventSource;
use App\Enums\Git\GitProviderKind;
use App\Enums\Git\WebhookDeliveryStatus;
use App\Enums\TeamRole;
use App\Events\Git\GitPushReceived;
use App\Events\Git\PullRequestEventReceived;
use App\Jobs\Git\PollConnectionNowJob;
use App\Models\Git\GitRepository;
use App\Models\Git\WebhookDelivery;
use App\Models\Team;
use App\Support\Git\ConnectionWebhookSignature;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitProviderFake;

/*
| GitLab, Gitea/Forgejo and generic git webhooks (docs/19 §5, docs/09 R17):
| fail closed, dedupe, normalization into the neutral push / pull request events.
*/

const PW_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const PW_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

beforeEach(function (): void {
    GitProviderFake::setUp();
    Event::fake([GitPushReceived::class, PullRequestEventReceived::class]);

    $this->team = Team::factory()->create();
    memberOf($this->team, TeamRole::Owner);
    $this->gitlab = GitProviderFake::connection($this->team, GitProviderKind::GitLab);
    $this->gitea = GitProviderFake::connection($this->team, GitProviderKind::Gitea);
});

/**
 * @return array<string, mixed>
 */
function glabPush(array $overrides = []): array
{
    return array_replace_recursive([
        'object_kind' => 'push',
        'ref' => 'refs/heads/main',
        'before' => PW_A,
        'after' => PW_B,
        'checkout_sha' => PW_B,
        'user_name' => 'Ada Lovelace',
        'user_username' => 'ada',
        'user_avatar' => GitProviderFake::GITLAB.'/uploads/-/system/user/avatar/7/avatar.png',
        'project' => ['id' => 42, 'path_with_namespace' => 'acme/platform/web', 'default_branch' => 'main', 'visibility_level' => 0],
        'commits' => [
            ['id' => PW_B, 'message' => 'Add pricing page', 'author' => ['name' => 'Ada', 'email' => 'ada@example.com'], 'added' => ['pages/pricing.tsx'], 'modified' => ['package.json'], 'removed' => []],
        ],
        'total_commits_count' => 1,
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function glabMergeRequest(string $action = 'open', ?int $sourceProject = 42, array $extra = []): array
{
    return array_replace_recursive([
        'object_kind' => 'merge_request',
        'user' => ['id' => 9, 'username' => 'grace'],
        'project' => ['id' => 42, 'path_with_namespace' => 'acme/platform/web', 'default_branch' => 'main', 'visibility_level' => 0],
        'object_attributes' => [
            'iid' => 7,
            'title' => 'Feature',
            'state' => 'opened',
            'action' => $action,
            'source_project_id' => $sourceProject,
            'target_project_id' => 42,
            'source_branch' => 'feature',
            'target_branch' => 'main',
            'author_id' => 9,
            'last_commit' => ['id' => PW_B],
        ],
        'labels' => [['title' => 'ui']],
    ], $extra);
}

/**
 * @return array<string, mixed>
 */
function fgPush(): array
{
    return [
        'ref' => 'refs/heads/main',
        'before' => PW_A,
        'after' => PW_B,
        'repository' => ['id' => 11, 'full_name' => 'acme/api', 'default_branch' => 'main', 'private' => true],
        'head_commit' => ['id' => PW_B, 'message' => 'Fix login', 'author' => ['name' => 'Ada', 'email' => 'ada@example.com', 'username' => 'ada']],
        'commits' => [['id' => PW_B, 'message' => 'Fix login', 'added' => [], 'modified' => ['api/login.go'], 'removed' => []]],
        'total_commits' => 1,
        'sender' => ['login' => 'ada', 'avatar_url' => 'https://evil.example.net/avatar.png'],
    ];
}

/**
 * @return array<string, mixed>
 */
function fgPullRequest(string $action = 'opened', int $headRepo = 11): array
{
    return [
        'action' => $action,
        'number' => 3,
        'repository' => ['id' => 11, 'full_name' => 'acme/api', 'default_branch' => 'main', 'private' => true],
        'pull_request' => [
            'number' => 3,
            'title' => 'Better login',
            'user' => ['login' => 'grace'],
            'head' => ['ref' => 'login', 'sha' => PW_B, 'repo' => ['id' => $headRepo]],
            'base' => ['ref' => 'main', 'repo' => ['id' => 11]],
            'labels' => [],
            'merged' => false,
        ],
        'sender' => ['login' => 'grace'],
    ];
}

test('a GitLab push with the right token becomes a GitPushReceived of the connection', function (): void {
    GitProviderFake::gitlabWebhook($this, $this->gitlab, 'Push Hook', glabPush())->assertStatus(202);

    Event::assertDispatched(GitPushReceived::class, fn (GitPushReceived $event): bool => $event->installation->is($this->gitlab)
        && $event->installation->gitProvider() === GitProviderKind::GitLab
        && $event->repositoryId === 42
        && $event->ref === 'refs/heads/main'
        && $event->after === PW_B
        && $event->headCommit->message === 'Add pricing page'
        && $event->headCommit->authorLogin === 'ada'
        && $event->headCommit->authorAvatarUrl === GitProviderFake::GITLAB.'/uploads/-/system/user/avatar/7/avatar.png'
        && $event->changedFiles === ['package.json', 'pages/pricing.tsx']
        && $event->source === GitEventSource::Webhook);

    expect(GitRepository::query()->where('git_connection_id', $this->gitlab->id)->sole())
        ->external_id->toBe(42)
        ->full_name->toBe('acme/platform/web')
        ->and(WebhookDelivery::query()->sole())
        ->git_connection_id->toBe($this->gitlab->id)
        ->status->toBe(WebhookDeliveryStatus::Processed);
});

test('GitLab webhooks fail closed: no token, a wrong token, another connection’s token', function (?string $token): void {
    if ($token === 'other') {
        $token = GitProviderFake::secret(GitProviderFake::connection(Team::factory()->create(), GitProviderKind::GitLab, accountId: 8));
    }

    GitProviderFake::gitlabWebhook($this, $this->gitlab, 'Push Hook', glabPush(), token: $token)->assertUnauthorized();

    Event::assertNotDispatched(GitPushReceived::class);
    expect(WebhookDelivery::query()->count())->toBe(0);
})->with([
    'missing' => [null],
    'wrong' => ['not-the-secret'],
    'other connection' => ['other'],
]);

test('a webhook for a connection that is not active, or of the wrong provider path, is refused', function (): void {
    $pending = GitProviderFake::connection($this->team, GitProviderKind::GitLab, GitConnectionStatus::Pending, accountId: 99);
    GitProviderFake::gitlabWebhook($this, $pending, 'Push Hook', glabPush())->assertUnauthorized();

    // A Gitea connection's id on the GitLab path.
    GitProviderFake::post($this, '/webhooks/gitlab/'.$this->gitea->public_id, json_encode(glabPush(), JSON_THROW_ON_ERROR), [
        'X-Gitlab-Event' => 'Push Hook',
        'X-Gitlab-Event-UUID' => 'b5b4a1a2-0000-4000-8000-000000000001',
        'X-Gitlab-Token' => GitProviderFake::secret($this->gitea),
    ])->assertUnauthorized();

    $this->postJson('/webhooks/gitlab/not-an-id', glabPush(), ['X-Gitlab-Token' => 'x'])->assertUnauthorized();
    Event::assertNotDispatched(GitPushReceived::class);
});

test('a replayed GitLab delivery is processed once', function (): void {
    GitProviderFake::gitlabWebhook($this, $this->gitlab, 'Push Hook', glabPush(), uuid: 'b5b4a1a2-0000-4000-8000-000000000002')->assertStatus(202);
    GitProviderFake::gitlabWebhook($this, $this->gitlab, 'Push Hook', glabPush(), uuid: 'b5b4a1a2-0000-4000-8000-000000000002')->assertStatus(202)->assertJson(['status' => 'duplicate']);

    Event::assertDispatchedTimes(GitPushReceived::class, 1);
});

test('a push the webhook already reported, a deleted branch and a skip marker emit nothing', function (): void {
    GitProviderFake::gitlabWebhook($this, $this->gitlab, 'Push Hook', glabPush());
    GitProviderFake::gitlabWebhook($this, $this->gitlab, 'Push Hook', glabPush());
    GitProviderFake::gitlabWebhook($this, $this->gitlab, 'Push Hook', glabPush(['after' => str_repeat('0', 40)]));
    GitProviderFake::gitlabWebhook($this, $this->gitlab, 'Push Hook', glabPush(['after' => str_repeat('c', 40), 'commits' => [['id' => str_repeat('c', 40), 'message' => 'docs [skip ci]']]]));

    Event::assertDispatchedTimes(GitPushReceived::class, 1);
});

test('large GitLab pushes report changed files as unknown', function (): void {
    GitProviderFake::gitlabWebhook($this, $this->gitlab, 'Push Hook', glabPush(['total_commits_count' => 25]));

    Event::assertDispatched(GitPushReceived::class, fn (GitPushReceived $event): bool => $event->changedFiles === null);
});

test('a GitLab merge request becomes a PullRequestEventReceived with GitLab’s numbers', function (): void {
    GitProviderFake::gitlabWebhook($this, $this->gitlab, 'Merge Request Hook', glabMergeRequest('update', extra: ['object_attributes' => ['oldrev' => PW_A]]))->assertStatus(202);

    Event::assertDispatched(PullRequestEventReceived::class, fn (PullRequestEventReceived $event): bool => $event->installation->is($this->gitlab)
        && $event->action === 'synchronize'
        && $event->number === 7
        && $event->headSha === PW_B
        && $event->headRef === 'feature'
        && $event->baseRef === 'main'
        && ! $event->isFork
        && $event->authorPermission === null
        && $event->labels === ['ui']);
});

test('GitLab fork merge requests look up the author’s access level; a failed lookup is no write access', function (int $status, array $body, string $expected): void {
    Http::fake([GitProviderFake::GITLAB.'/api/v4/projects/42/members/all/9' => Http::response($body, $status)]);

    GitProviderFake::gitlabWebhook($this, $this->gitlab, 'Merge Request Hook', glabMergeRequest('open', sourceProject: 1234));

    Event::assertDispatched(PullRequestEventReceived::class, fn (PullRequestEventReceived $event): bool => $event->isFork && $event->authorPermission === $expected);
})->with([
    'developer' => [200, ['access_level' => 30], 'write'],
    'reporter' => [200, ['access_level' => 20], 'read'],
    'not a member' => [404, [], 'none'],
    'GitLab down' => [500, [], 'unknown'],
]);

test('a merged GitLab merge request closes; approvals and other actions map', function (): void {
    GitProviderFake::gitlabWebhook($this, $this->gitlab, 'Merge Request Hook', glabMergeRequest('merge', extra: ['object_attributes' => ['state' => 'merged']]));
    GitProviderFake::gitlabWebhook($this, $this->gitlab, 'Merge Request Hook', glabMergeRequest('approved'));

    Event::assertDispatchedTimes(PullRequestEventReceived::class, 1);
    Event::assertDispatched(PullRequestEventReceived::class, fn (PullRequestEventReceived $event): bool => $event->action === 'closed' && $event->merged);
});

test('a Gitea/Forgejo push is verified by the HMAC of the raw body and normalized', function (string $header): void {
    GitProviderFake::giteaWebhook($this, $this->gitea, 'push', fgPush(), header: $header)->assertStatus(202);

    Event::assertDispatched(GitPushReceived::class, fn (GitPushReceived $event): bool => $event->installation->is($this->gitea)
        && $event->repositoryId === 11
        && $event->headCommit->message === 'Fix login'
        && $event->headCommit->authorLogin === 'ada'
        // Avatars only from the connection's origin or Gravatar.
        && $event->headCommit->authorAvatarUrl === null
        && $event->changedFiles === ['api/login.go']);
})->with(['Gitea' => ['X-Gitea-Signature'], 'Forgejo' => ['X-Forgejo-Signature']]);

test('Gitea webhooks fail closed', function (?string $secret): void {
    GitProviderFake::giteaWebhook($this, $this->gitea, 'push', fgPush(), secret: $secret)->assertUnauthorized();

    Event::assertNotDispatched(GitPushReceived::class);
})->with(['unsigned' => [null], 'wrong secret' => ['nope']]);

test('a Gitea pull request syncs; forks are detected by repository id and the permission is looked up', function (): void {
    Http::fake([GitProviderFake::GITEA.'/api/v1/repos/acme/api/collaborators/grace/permission' => Http::response(['permission' => 'read'])]);

    GitProviderFake::giteaWebhook($this, $this->gitea, 'pull_request_sync', fgPullRequest('synchronized'));
    GitProviderFake::giteaWebhook($this, $this->gitea, 'pull_request', fgPullRequest('opened', headRepo: 99));

    Event::assertDispatched(PullRequestEventReceived::class, fn (PullRequestEventReceived $event): bool => $event->action === 'synchronize' && ! $event->isFork && $event->number === 3);
    Event::assertDispatched(PullRequestEventReceived::class, fn (PullRequestEventReceived $event): bool => $event->action === 'opened' && $event->isFork && $event->authorPermission === 'read');
});

test('the generic git webhook verifies its HMAC and polls the repository now', function (): void {
    Queue::fake();
    $plain = GitProviderFake::connection($this->team, GitProviderKind::Git);
    $body = '{"ref":"refs/heads/main"}';

    GitProviderFake::post($this, '/webhooks/git/'.$plain->public_id, $body, ['X-Launchroom-Signature' => 'sha256='.hash_hmac('sha256', $body, 'wrong')])->assertUnauthorized();
    Queue::assertNothingPushed();

    GitProviderFake::post($this, '/webhooks/git/'.$plain->public_id, $body, ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, GitProviderFake::secret($plain))])->assertStatus(202);
    Queue::assertPushed(PollConnectionNowJob::class, fn (PollConnectionNowJob $job): bool => $job->connectionId === $plain->id);
});

test('signature checks fail closed for empty secrets and headers', function (GitProviderKind $provider): void {
    expect(ConnectionWebhookSignature::isValid($provider, '{}', '', 'secret'))->toBeFalse()
        ->and(ConnectionWebhookSignature::isValid($provider, '{}', 'x', ''))->toBeFalse()
        ->and(ConnectionWebhookSignature::isValid(GitProviderKind::GitHub, '{}', 'x', 'x'))->toBeFalse();
})->with([GitProviderKind::GitLab, GitProviderKind::Gitea, GitProviderKind::Git]);
