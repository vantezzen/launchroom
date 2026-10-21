<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Git\GitHubInstallation;
use App\Models\Git\GitHubRepository;
use App\Models\Team;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\GitHubFake;

beforeEach(function (): void {
    GitHubFake::setUp();
    $this->team = Team::factory()->create();
    $this->admin = memberOf($this->team, TeamRole::Admin);
    $this->githubApp = GitHubFake::app($this->team);
});

function ghFakeInstallationApi(int $installationId = 100): void
{
    Http::fake([
        "https://api.github.com/app/installations/{$installationId}/access_tokens" => GitHubFake::tokenResponse(),
        "https://api.github.com/app/installations/{$installationId}" => Http::response(GitHubFake::installationJson($installationId)),
        'https://api.github.com/app/installations/*' => Http::response(['message' => 'Not Found'], 404),
        'https://api.github.com/installation/repositories*' => Http::response([
            'total_count' => 2,
            'repositories' => [
                ['id' => 555, 'full_name' => 'octo-org/web', 'private' => true, 'default_branch' => 'main', 'archived' => false, 'pushed_at' => '2026-10-17T10:00:00Z'],
                ['id' => 556, 'full_name' => 'octo-org/api', 'private' => false, 'default_branch' => 'trunk', 'archived' => false, 'pushed_at' => null],
            ],
        ]),
    ]);
}

test('the setup callback stores an installation only after GitHub confirms it with the App JWT', function (): void {
    ghFakeInstallationApi();

    $this->actingAs($this->admin)
        ->get(route('git.github.setup', ['installation_id' => '100', 'setup_action' => 'install']))
        ->assertRedirect(route('git.edit'))
        ->assertSessionHasNoErrors();

    $installation = GitHubInstallation::query()->sole();

    expect($installation->team_id)->toBe($this->team->id)
        ->and($installation->account_login)->toBe('octo-org')
        ->and(AuditEvent::query()->where('action', AuditAction::GitHubInstallationLinked->value)->count())->toBe(1)
        // The repository refresh ran (sync queue) and cached both repositories.
        ->and(GitHubRepository::query()->orderBy('github_repo_id')->pluck('default_branch')->all())->toBe(['main', 'trunk'])
        ->and($installation->refresh()->repositories_synced_at)->not->toBeNull();

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://api.github.com/app/installations/100') {
            return false;
        }

        $jwt = substr($request->header('Authorization')[0] ?? '', strlen('Bearer '));
        $claims = json_decode(base64_decode(strtr(explode('.', $jwt)[1] ?? '', '-_', '+/')), true);

        return ($claims['iss'] ?? null) === '4242';
    });
});

test('a forged installation id is rejected and nothing is stored', function (): void {
    ghFakeInstallationApi();

    // Installation 999 belongs to someone else's app: GitHub answers 404 to our JWT.
    $this->actingAs($this->admin)
        ->get(route('git.github.setup', ['installation_id' => '999', 'setup_action' => 'install']))
        ->assertRedirect(route('git.edit'))
        ->assertSessionHasErrors('github');

    expect(GitHubInstallation::query()->count())->toBe(0);
});

test('an installation GitHub attributes to another app is rejected', function (): void {
    Http::fake(['https://api.github.com/app/installations/100' => Http::response(GitHubFake::installationJson(100, appId: 1))]);

    $this->actingAs($this->admin)
        ->get(route('git.github.setup', ['installation_id' => '100']))
        ->assertSessionHasErrors('github');

    expect(GitHubInstallation::query()->count())->toBe(0);
});

test('an installation of another team’s app is not linked to this team', function (): void {
    $otherTeam = Team::factory()->create();
    GitHubFake::app($otherTeam, 7777);
    // Only the other team's app could see installation 100; ours gets 404.
    Http::fake(['https://api.github.com/app/installations/*' => Http::response(['message' => 'Not Found'], 404)]);

    $this->actingAs($this->admin)
        ->get(route('git.github.setup', ['installation_id' => '100']))
        ->assertSessionHasErrors('github');

    expect(GitHubInstallation::query()->count())->toBe(0);
});

test('malformed ids and installation requests never reach GitHub', function (): void {
    Http::fake();

    $this->actingAs($this->admin)->get(route('git.github.setup', ['installation_id' => '1 OR 1=1']))->assertSessionHasErrors('github');
    $this->actingAs($this->admin)->get(route('git.github.setup', ['setup_action' => 'request']))->assertSessionHasNoErrors();

    Http::assertNothingSent();
});

test('only owners and admins can link installations', function (): void {
    Http::fake();

    $this->actingAs(memberOf($this->team, TeamRole::Developer))
        ->get(route('git.github.setup', ['installation_id' => '100']))
        ->assertForbidden();
});

test('refreshing replaces the repository cache', function (): void {
    ghFakeInstallationApi();
    $installation = GitHubFake::installation($this->githubApp);
    GitHubFake::repository($installation, 1, 'octo-org/removed');

    $this->actingAs($this->admin)
        ->post(route('git.installations.refresh', $installation->public_id))
        ->assertRedirect();

    expect(GitHubRepository::query()->orderBy('github_repo_id')->pluck('full_name')->all())->toBe(['octo-org/web', 'octo-org/api']);

    // The listing token is installation-wide but metadata-only.
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request->data() === ['permissions' => ['metadata' => 'read']]);
});

test('installations of other teams are not found', function (): void {
    $foreign = GitHubFake::installation(GitHubFake::app(Team::factory()->create(), 99));

    $this->actingAs($this->admin)
        ->post(route('git.installations.refresh', $foreign->public_id))
        ->assertNotFound();
});
