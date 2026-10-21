<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Previews\SweepOrphanPreviews;
use App\Actions\Services\CreateService;
use App\Data\Audit\AuditActor;
use App\Data\Services\CreateServiceData;
use App\Enums\Previews\PreviewProvider;
use App\Enums\Previews\PreviewStatus;
use App\Enums\ServiceKind;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewSettings;
use App\Models\Team;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\GitHubFake;
use Tests\Support\PreviewFlow;
use Tests\Support\Projects;

const PG_HEAD = 'cccccccccccccccccccccccccccccccccccccccc';

beforeEach(function (): void {
    GitHubFake::setUp();
    Projects::useTestKek();
    config(['launchroom.domains.localhost' => true]);
    $this->team = Team::factory()->create();
    [$this->server] = Projects::server($this->team);
    $this->githubApp = GitHubFake::app($this->team);
    $this->installation = GitHubFake::installation($this->githubApp);
    GitHubFake::repository($this->installation, 555, 'octo-org/web');
    Http::fake([
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse('ghs_token'),
        'https://api.github.com/repos/octo-org/web/commits/main' => Http::response(['sha' => PreviewFlow::SHA_MAIN, 'commit' => ['message' => 'init']]),
        'https://api.github.com/repos/octo-org/web/compare/*' => Http::response(['files' => [['filename' => 'src/app.ts'], ['filename' => 'README.md']]]),
        'https://api.github.com/repos/octo-org/web/pulls/42' => Http::response(['state' => 'closed']),
        'https://api.github.com/repos/octo-org/web/deployments' => Http::response(['id' => 9001], 201),
        'https://api.github.com/repos/octo-org/web/deployments/*' => Http::response(['id' => 1], 201),
        'https://api.github.com/repos/octo-org/web/check-runs*' => Http::response(['id' => 77], 201),
        'https://api.github.com/repos/octo-org/web/issues/*' => Http::response(['id' => 55], 201),
    ]);
    $this->project = Projects::create($this->team, 'shop');
    $this->web = resolve(CreateService::class)->handle($this->project, CreateServiceData::from([
        'name' => 'web', 'kind' => ServiceKind::App,
        'source' => ['type' => 'git', 'repositoryId' => 555, 'fullName' => 'octo-org/web', 'installationId' => $this->installation->id],
    ]), null, AuditActor::system());
    resolve(DeployEnvironment::class)->handle($this->project->production()->sole(), null, AuditActor::system());
    PreviewFlow::run($this->server);
    PreviewSettings::forProject($this->project)->forceFill(['enabled' => true])->save();
});

/**
 * @return array<string, mixed>
 */
function pgPayload(string $action = 'opened'): array
{
    return [
        'action' => $action,
        'number' => 42,
        'installation' => ['id' => 100],
        'repository' => ['id' => 555, 'full_name' => 'octo-org/web', 'private' => true],
        'pull_request' => [
            'title' => 'Add pricing',
            'draft' => false,
            'merged' => $action === 'closed',
            'user' => ['login' => 'contributor'],
            'labels' => [],
            'head' => ['sha' => PG_HEAD, 'ref' => 'feature/pricing', 'repo' => ['id' => 555, 'full_name' => 'octo-org/web']],
            'base' => ['ref' => 'main', 'repo' => ['id' => 555, 'full_name' => 'octo-org/web']],
        ],
    ];
}

test('a pull_request webhook creates the preview with the PR’s changed files; GitHub gets one deployment for the preview environment', function (): void {
    GitHubFake::webhook($this, $this->githubApp, 'pull_request', pgPayload())->assertStatus(202);

    $preview = Preview::query()->sole();
    expect($preview->provider)->toBe(PreviewProvider::GitHub)
        ->and($preview->repository_name)->toBe('octo-org/web')
        ->and($preview->changed_files)->toBe(['src/app.ts', 'README.md'])
        ->and($preview->environment?->slug)->toBe('pr-42');

    PreviewFlow::run($this->server);

    expect($preview->refresh()->status)->toBe(PreviewStatus::Running)
        ->and($preview->github_deployment_id)->toBe(9001)
        ->and($preview->github_deployment_sha)->toBe(PG_HEAD);

    $deployments = collect(Http::recorded())->filter(static fn (array $pair): bool => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/repos/octo-org/web/deployments'));
    $statuses = collect(Http::recorded())->filter(static fn (array $pair): bool => preg_match('#/deployments/9001/statuses$#', $pair[0]->url()) === 1)->map(static fn (array $pair): array => $pair[0]->data());
    $previewDeployments = $deployments->filter(static fn (array $pair): bool => ($pair[0]->data()['environment'] ?? null) === 'preview/pr-42');

    expect($previewDeployments)->toHaveCount(1)
        ->and($previewDeployments->first()[0]->data()['transient_environment'])->toBeTrue()
        ->and($statuses->last()['state'])->toBe('success')
        ->and($statuses->last()['environment_url'])->toBe('https://web-pr-42.shop.localhost')
        // Per-service: check runs and the comment row, no extra GitHub deployments.
        ->and(collect(Http::recorded())->contains(static fn (array $pair): bool => str_contains($pair[0]->url(), '/check-runs') && str_contains(json_encode($pair[0]->data(), JSON_UNESCAPED_SLASHES) ?: '', 'preview/pr-42')))->toBeTrue()
        ->and(collect(Http::recorded())->contains(static fn (array $pair): bool => str_contains($pair[0]->url(), '/issues/42/comments')))->toBeTrue();
});

test('closing the PR deactivates the GitHub deployment and marks the comment', function (): void {
    GitHubFake::webhook($this, $this->githubApp, 'pull_request', pgPayload())->assertStatus(202);
    PreviewFlow::run($this->server);

    GitHubFake::webhook($this, $this->githubApp, 'pull_request', pgPayload('closed'), 'delivery-close')->assertStatus(202);

    expect(Preview::query()->sole()->status)->toBe(PreviewStatus::Closed)
        ->and(collect(Http::recorded())->contains(static fn (array $pair): bool => str_ends_with($pair[0]->url(), '/deployments/9001/statuses') && ($pair[0]->data()['state'] ?? null) === 'inactive'))->toBeTrue()
        ->and(collect(Http::recorded())->contains(static fn (array $pair): bool => $pair[0]->method() === 'PATCH' && str_contains($pair[0]->url(), '/issues/comments/') && str_contains((string) ($pair[0]->data()['body'] ?? ''), 'removed')))->toBeTrue();
});

test('the orphan sweep closes previews whose pull request closed while webhooks were missed', function (): void {
    GitHubFake::webhook($this, $this->githubApp, 'pull_request', pgPayload())->assertStatus(202);

    expect(resolve(SweepOrphanPreviews::class)->handle())->toBe(1)
        ->and(Preview::query()->sole()->status)->toBe(PreviewStatus::Closed)
        ->and(Preview::query()->sole()->environment_id)->toBeNull()
        ->and(collect(Http::recorded())->contains(static fn (array $pair): bool => $pair[0] instanceof Request && str_ends_with($pair[0]->url(), '/pulls/42')))->toBeTrue();
});
