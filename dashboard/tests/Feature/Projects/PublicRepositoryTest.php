<?php

declare(strict_types=1);

use App\Actions\Git\PollConnectionRef;
use App\Enums\AuditAction;
use App\Enums\Git\GitAuthMethod;
use App\Enums\Git\GitConnectionStatus;
use App\Enums\Git\GitProviderKind;
use App\Enums\TeamRole;
use App\Events\Git\GitPushReceived;
use App\Models\AuditEvent;
use App\Models\Git\GitConnection;
use App\Models\Git\GitRepository;
use App\Models\Team;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\GitHubFake;

/*
| "Paste a Git URL" on New project (docs/19 §4.3, D70): public HTTPS
| repositories without a provider connection, read over git smart HTTP,
| with the template made for them.
*/

function prPkt(string $line): string
{
    return sprintf('%04x', strlen($line) + 4).$line;
}

/** A protocol v2 ls-refs answer. */
function prLsRefs(array $branches, string $head = 'main'): array
{
    $body = prPkt(($branches[$head] ?? str_repeat('0', 40))." HEAD symref-target:refs/heads/{$head}\n");

    foreach ($branches as $name => $sha) {
        $body .= prPkt("{$sha} refs/heads/{$name}\n");
    }

    return [$body.'0000', 200, ['Content-Type' => 'application/x-git-upload-pack-result']];
}

beforeEach(function (): void {
    GitHubFake::setUp();
    $this->team = Team::factory()->create();
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    $this->a = str_repeat('a', 40);
    $this->b = str_repeat('b', 40);
});

test('checking a pasted URL reads its branches over smart HTTP and stores nothing', function (): void {
    Http::fake(['https://github.com/acme/shop.git/git-upload-pack' => Http::response(...prLsRefs(['main' => $this->a, 'dev' => $this->b]))]);

    $this->actingAs($this->developer)
        ->getJson(route('projects.import.check-url', ['url' => 'github.com/acme/shop/tree/dev']))
        ->assertOk()
        ->assertJson([
            'url' => 'https://github.com/acme/shop.git',
            'fullName' => 'github.com/acme/shop',
            'name' => 'shop',
            'provider' => 'github',
            'defaultBranch' => 'main',
            'branches' => ['main', 'dev'],
            'branch' => 'dev',
            'template' => null,
            'problem' => null,
        ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->header('Git-Protocol') === ['version=2']
        && str_contains($request->body(), 'command=ls-refs')
        && ! str_contains($request->url(), 'api.github.com'));
    expect(GitConnection::query()->count())->toBe(0);
});

test('servers without protocol v2 are read from the v0 advertisement', function (): void {
    $body = prPkt("# service=git-upload-pack\n").'0000'
        .prPkt("{$this->a} HEAD\0multi_ack symref=HEAD:refs/heads/trunk\n")
        .prPkt("{$this->a} refs/heads/trunk\n")
        .prPkt("{$this->b} refs/pull/1/head\n").'0000';

    Http::fake([
        'https://git.example.org/app/git-upload-pack' => Http::response('', 403),
        'https://git.example.org/app/info/refs*' => Http::response($body, 200, ['Content-Type' => 'application/x-git-upload-pack-advertisement']),
    ]);

    $this->actingAs($this->developer)
        ->getJson(route('projects.import.check-url', ['url' => 'https://git.example.org/app']))
        ->assertOk()
        ->assertJson(['url' => 'https://git.example.org/app', 'provider' => 'git', 'defaultBranch' => 'trunk', 'branches' => ['trunk']]);
});

test('the upstream repository of a template recommends the template', function (): void {
    Http::fake(['https://github.com/umami-software/umami.git/git-upload-pack' => Http::response(...prLsRefs(['master' => $this->a], 'master'))]);

    $this->actingAs($this->developer)
        ->getJson(route('projects.import.check-url', ['url' => 'https://www.GitHub.com/umami-software/umami.git/']))
        ->assertOk()
        ->assertJson(['template' => 'umami', 'defaultBranch' => 'master']);
});

test('a missing or private repository is a calm problem, not an error', function (): void {
    Http::fake([
        'https://github.com/acme/secret.git/git-upload-pack' => Http::response('', 401),
        'https://github.com/acme/secret.git/info/refs*' => Http::response('', 401),
    ]);

    $this->actingAs($this->developer)
        ->getJson(route('projects.import.check-url', ['url' => 'github.com/acme/secret']))
        ->assertOk()
        ->assertJsonPath('branches', [])
        ->assertJsonPath('problem', 'github.com doesn’t show acme/secret publicly. If it’s private, connect GitHub in Settings → Git.');
});

test('a host that asks to slow down is left alone for a while', function (): void {
    Http::fake(['https://gitlab.com/*' => Http::response('', 429, ['Retry-After' => '120'])]);

    $this->actingAs($this->developer)
        ->getJson(route('projects.import.check-url', ['url' => 'gitlab.com/group/app']))
        ->assertOk()
        ->assertJsonPath('problem', 'The git server asked launchroom to slow down. It checks again in a few minutes.');

    Http::assertSentCount(1);

    $this->actingAs($this->developer)
        ->getJson(route('projects.import.check-url', ['url' => 'gitlab.com/group/other']))
        ->assertJsonPath('problem', 'gitlab.com asked launchroom to slow down. It checks again in a few minutes.');

    Http::assertSentCount(1);
});

test('URLs are checked by grammar before anything is fetched', function (string $url): void {
    $this->actingAs($this->developer)
        ->getJson(route('projects.import.check-url', ['url' => $url]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    Http::assertNothingSent();
})->with([
    'http' => ['http://github.com/acme/app'],
    'ssh' => ['git@github.com:acme/app.git'],
    'credentials' => ['https://me:token@github.com/acme/app'],
    'not a repository' => ['https://github.com/acme'],
]);

test('private networks stay out of reach', function (): void {
    $this->actingAs($this->developer)
        ->getJson(route('projects.import.check-url', ['url' => 'https://git.internal.example.com/app']))
        ->assertOk()
        ->assertJsonPath('branches', [])
        ->assertJson(fn ($json) => $json->where('problem', fn (string $problem): bool => str_contains($problem, 'not reachable'))->etc());

    Http::assertNothingSent();
});

test('continuing adds the repository once per team, for anyone who creates projects', function (): void {
    Http::fake(['https://github.com/acme/shop.git/git-upload-pack' => Http::response(...prLsRefs(['main' => $this->a]))]);

    $this->actingAs($this->viewer)->postJson(route('projects.import.add-url'), ['url' => 'github.com/acme/shop'])->assertForbidden();

    $first = $this->actingAs($this->developer)
        ->postJson(route('projects.import.add-url'), ['url' => 'github.com/acme/shop'])
        ->assertOk()
        ->assertJson(['fullName' => 'github.com/acme/shop', 'provider' => 'git', 'defaultBranch' => 'main', 'private' => false])
        ->json('id');

    $connection = GitConnection::query()->sole();
    expect($connection->provider)->toBe(GitProviderKind::Git)
        ->and($connection->auth_method)->toBe(GitAuthMethod::None)
        ->and($connection->status)->toBe(GitConnectionStatus::Active)
        ->and($connection->base_url)->toBe('https://github.com/acme/shop.git')
        ->and($connection->allow_private_network)->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditAction::GitConnectionCreated->value)->count())->toBe(1);

    $again = $this->actingAs($this->developer)
        ->postJson(route('projects.import.add-url'), ['url' => 'https://github.com/acme/shop/'])
        ->assertOk()
        ->json('id');

    expect($again)->toBe($first)
        ->and(GitConnection::query()->count())->toBe(1)
        ->and(GitRepository::query()->count())->toBe(1);

    // Another team gets its own row; nothing is shared across teams.
    $other = Team::factory()->create();
    $stranger = memberOf($other, TeamRole::Developer);
    $this->actingAs($stranger)->postJson(route('projects.import.add-url'), ['url' => 'github.com/acme/shop'])->assertOk();
    expect(GitConnection::query()->count())->toBe(2);
});

test('a repository launchroom can’t read is not added', function (): void {
    Http::fake([
        'https://github.com/acme/gone.git/git-upload-pack' => Http::response('', 404),
        'https://github.com/acme/gone.git/info/refs*' => Http::response('', 404),
    ]);

    $this->actingAs($this->developer)
        ->postJson(route('projects.import.add-url'), ['url' => 'github.com/acme/gone'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    expect(GitConnection::query()->count())->toBe(0);
});

test('polling a public repository asks for its one branch', function (): void {
    Event::fake([GitPushReceived::class]);
    // Adding checks twice (the URL, then the connection), then two polls.
    Http::fake(['https://github.com/acme/shop.git/git-upload-pack' => Http::sequence()
        ->push(...prLsRefs(['main' => $this->a]))
        ->push(...prLsRefs(['main' => $this->a]))
        ->push(...prLsRefs(['main' => $this->a]))
        ->push(...prLsRefs(['main' => $this->b])),
    ]);

    $this->actingAs($this->developer)->postJson(route('projects.import.add-url'), ['url' => 'github.com/acme/shop'])->assertOk();
    $connection = GitConnection::query()->sole();

    expect(resolve(PollConnectionRef::class)->handle($connection, 0, 'refs/heads/main'))->toBeFalse()
        ->and(resolve(PollConnectionRef::class)->handle($connection, 0, 'refs/heads/main'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'ref-prefix refs/heads/main'));
    Event::assertDispatched(GitPushReceived::class, fn (GitPushReceived $event): bool => $event->after === $this->b);
});

test('the start page offers the templates and who may connect git', function (): void {
    $this->actingAs($this->developer)->get(route('projects.import'))
        ->assertInertia(fn ($page) => $page
            ->component('projects/new')
            ->where('canConnectGit', false)
            ->has('templates')
            ->has('categories'));
});

/*
| A pasted URL that one of the team's connections reaches goes through that
| connection: its row, branches and push delivery, never the public path.
*/

function prGitHub(Team $team, bool $private = true, bool $healthy = false): array
{
    $app = GitHubFake::app($team);

    if ($healthy) {
        $app->forceFill(['webhook_last_received_at' => now()->subMinutes(5)])->save();
    }

    $repository = GitHubFake::repository(GitHubFake::installation($app), 555, 'octo-org/Shop');
    $repository->forceFill(['private' => $private])->save();

    return [$app, $repository];
}

function prBranchesFake(): void
{
    Http::fake([
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse(),
        'https://api.github.com/repos/octo-org/Shop/branches*' => Http::response([
            ['name' => 'main', 'commit' => ['sha' => str_repeat('a', 40)], 'protected' => false],
            ['name' => 'dev', 'commit' => ['sha' => str_repeat('b', 40)], 'protected' => false],
        ]),
    ]);
}

test('a private repository the GitHub app reaches is found through the app', function (): void {
    [, $repository] = prGitHub($this->team);
    prBranchesFake();

    $this->actingAs($this->developer)
        ->getJson(route('projects.import.check-url', ['url' => 'https://github.com/octo-org/shop.git']))
        ->assertOk()
        ->assertJson([
            'problem' => null,
            'private' => true,
            'via' => 'your GitHub app',
            'pushes' => 'polling',
            'defaultBranch' => 'main',
            'branches' => ['main', 'dev'],
            'repository' => ['id' => $repository->public_id, 'provider' => 'github'],
        ]);

    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://github.com/'));

    // Continue hands back the same row as Import, nothing new is added.
    $this->actingAs($this->developer)
        ->postJson(route('projects.import.add-url'), ['url' => 'github.com/octo-org/shop'])
        ->assertOk()
        ->assertJsonPath('id', $repository->public_id);
    expect(GitConnection::query()->count())->toBe(0);
});

test('an own public repository with healthy webhooks says pushes deploy right away', function (): void {
    prGitHub($this->team, private: false, healthy: true);
    prBranchesFake();

    $this->actingAs($this->developer)
        ->getJson(route('projects.import.check-url', ['url' => 'github.com/octo-org/shop/tree/dev']))
        ->assertOk()
        ->assertJson(['private' => false, 'pushes' => 'webhook', 'branch' => 'dev']);
});

test('a repository the app doesn’t cover is read publicly and polled', function (): void {
    prGitHub($this->team);
    Http::fake(['https://github.com/someone/public.git/git-upload-pack' => Http::response(...prLsRefs(['main' => $this->a]))]);

    $this->actingAs($this->developer)
        ->getJson(route('projects.import.check-url', ['url' => 'github.com/someone/public']))
        ->assertOk()
        ->assertJson(['repository' => null, 'pushes' => 'polling', 'problem' => null]);
});

test('a private repository nothing covers says how to get access', function (bool $hasApp, string $fix): void {
    if ($hasApp) {
        prGitHub($this->team);
    }

    Http::fake([
        'https://github.com/acme/secret.git/git-upload-pack' => Http::response('', 404),
        'https://github.com/acme/secret.git/info/refs*' => Http::response('', 404),
    ]);

    $this->actingAs($this->developer)
        ->getJson(route('projects.import.check-url', ['url' => 'github.com/acme/secret']))
        ->assertOk()
        ->assertJsonPath('problem', "github.com doesn’t show acme/secret publicly. {$fix}");
})->with([
    'with the app' => [true, 'If it’s private, install your GitHub app on acme or give it access to this repository.'],
    'without' => [false, 'If it’s private, connect GitHub in Settings → Git.'],
]);

test('another team’s connections are never used', function (): void {
    $other = Team::factory()->create();
    prGitHub($other);
    Http::fake([
        'https://github.com/octo-org/Shop.git/git-upload-pack' => Http::response('', 404),
        'https://github.com/octo-org/Shop.git/info/refs*' => Http::response('', 404),
    ]);

    $this->actingAs($this->developer)
        ->getJson(route('projects.import.check-url', ['url' => 'github.com/octo-org/Shop']))
        ->assertOk()
        ->assertJsonPath('repository', null)
        ->assertJson(fn ($json) => $json->where('problem', fn (string $problem): bool => str_contains($problem, 'connect GitHub'))->etc());
});
