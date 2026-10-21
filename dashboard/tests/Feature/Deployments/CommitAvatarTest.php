<?php

declare(strict_types=1);

use App\Actions\Deployments\RedeployService;
use App\Data\Audit\AuditActor;
use App\Enums\TeamRole;
use App\Events\Git\GitPushReceived;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Deployment;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DeploymentUi;
use Tests\Support\GitHubFake;

/*
| Commit author avatars (docs/08 §13): captured from GitHub payloads at
| deployment creation, only ever on avatars.githubusercontent.com.
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function avatarPushPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'ref' => 'refs/heads/main',
        'before' => str_repeat('a', 40),
        'after' => str_repeat('b', 40),
        'deleted' => false,
        'forced' => false,
        'installation' => ['id' => 100],
        'repository' => ['id' => 555, 'full_name' => 'octo-org/web', 'private' => true, 'default_branch' => 'main'],
        'head_commit' => [
            'id' => str_repeat('b', 40),
            'message' => 'Add pricing page',
            'author' => ['name' => 'Octo Cat', 'email' => 'octo@example.com', 'username' => 'octocat'],
        ],
        'sender' => ['login' => 'octocat', 'avatar_url' => 'https://avatars.githubusercontent.com/u/583231?v=4'],
        'commits' => [],
    ], $overrides);
}

test('a push takes the sender’s avatar when the sender is the commit author', function (array $sender, ?string $expected): void {
    GitHubFake::setUp();
    Event::fake([GitPushReceived::class]);
    $team = Team::factory()->create();
    $app = GitHubFake::app($team);
    GitHubFake::repository(GitHubFake::installation($app));

    GitHubFake::webhook($this, $app, 'push', avatarPushPayload(['sender' => $sender]))->assertStatus(202);

    Event::assertDispatched(GitPushReceived::class, fn (GitPushReceived $event): bool => $event->headCommit->authorAvatarUrl === $expected);
})->with([
    'author pushed' => [['login' => 'OctoCat', 'avatar_url' => 'https://avatars.githubusercontent.com/u/583231?v=4'], 'https://avatars.githubusercontent.com/u/583231?v=4'],
    'someone else pushed' => [['login' => 'mara', 'avatar_url' => 'https://avatars.githubusercontent.com/u/9?v=4'], 'https://avatars.githubusercontent.com/octocat?v=4'],
    'foreign avatar host' => [['login' => 'octocat', 'avatar_url' => 'https://evil.test/u/583231'], 'https://avatars.githubusercontent.com/octocat?v=4'],
]);

test('deployment lists carry the avatar; redeploys keep it', function (): void {
    $world = DeploymentUi::make();
    $deployment = $world->latest($world->web);
    $deployment->forceFill(['commit_author' => 'octocat', 'commit_author_avatar_url' => 'https://avatars.githubusercontent.com/u/583231?v=4'])->save();

    $this->actingAs($world->member(TeamRole::Viewer))->get(route('deployments.project', $world->project->public_id))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('deployments', fn ($rows): bool => collect($rows)->contains(fn (array $row): bool => $row['commitAuthorAvatarUrl'] === 'https://avatars.githubusercontent.com/u/583231?v=4')));

    // A value that isn't a GitHub avatar never reaches the page.
    $deployment->forceFill(['commit_author_avatar_url' => 'https://evil.test/x.png'])->save();
    $this->actingAs($world->member(TeamRole::Viewer))->get(route('deployments.project', $world->project->public_id))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('deployments', fn ($rows): bool => collect($rows)->every(fn (array $row): bool => $row['commitAuthorAvatarUrl'] === null)));

    $deployment->forceFill(['commit_author_avatar_url' => 'https://avatars.githubusercontent.com/u/1?v=4'])->save();
    $redeploy = resolve(RedeployService::class)->handle($deployment->serviceInstance, $deployment, null, AuditActor::system());
    expect(Deployment::query()->findOrFail($redeploy->id)->commit_author_avatar_url)->toBe('https://avatars.githubusercontent.com/u/1?v=4');
});

test('the CSP allows images from GitHub’s avatar host, Gravatar and active git connections only (docs/19 §7)', function (): void {
    $policy = (new SecurityHeaders)->policy(Request::create('/'), 'nonce');

    expect($policy)->toContain("img-src 'self' data: blob: https://avatars.githubusercontent.com https://secure.gravatar.com;");
});
