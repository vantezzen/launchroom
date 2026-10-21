<?php

declare(strict_types=1);

use App\Data\Git\DeploymentStatusReport;
use App\Enums\Git\DeploymentReportState;
use App\Jobs\Git\ReportDeploymentStatusJob;
use App\Models\Git\GitHubDeploymentReport;
use App\Models\Git\GitHubPullRequestComment;
use App\Models\Team;
use App\Services\GitHub\GitHubDeploymentReporter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\GitHubFake;

beforeEach(function (): void {
    GitHubFake::setUp();
    $this->team = Team::factory()->create();
    $this->githubApp = GitHubFake::app($this->team);
    $this->installation = GitHubFake::installation($this->githubApp);
    GitHubFake::repository($this->installation, 555, 'octo-org/web');
});

function ghStatusReport(object $test, DeploymentReportState $state, array $overrides = []): DeploymentStatusReport
{
    return new DeploymentStatusReport(...[
        'teamId' => $test->team->id,
        'installationId' => $test->installation->id,
        'repositoryId' => 555,
        'deploymentKey' => 'dpl_01JB7Z6X9Q3T8M5N2P4R6S8V0W',
        'serviceName' => 'web',
        'environmentName' => 'pr-42',
        'productionEnvironment' => false,
        'transientEnvironment' => true,
        'commitSha' => str_repeat('c', 40),
        'state' => $state,
        'deploymentUrl' => 'https://launchroom.example.com/deployments/dpl_01JB7Z6X9Q3T8M5N2P4R6S8V0W',
        'environmentUrl' => 'https://web-pr-42.apps.example.com',
        'pullRequestNumber' => 42,
        ...$overrides,
    ]);
}

function ghFakeReportingApi(): void
{
    Http::fake([
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse(),
        'https://api.github.com/repos/octo-org/web/deployments/77/statuses' => Http::response(['id' => 1], 201),
        'https://api.github.com/repos/octo-org/web/deployments' => Http::response(['id' => 77], 201),
        'https://api.github.com/repos/octo-org/web/check-runs/88' => Http::response(['id' => 88]),
        'https://api.github.com/repos/octo-org/web/check-runs' => Http::response(['id' => 88], 201),
        'https://api.github.com/repos/octo-org/web/issues/comments/99' => Http::response(['id' => 99]),
        'https://api.github.com/repos/octo-org/web/issues/42/comments' => Http::response(['id' => 99], 201),
    ]);
}

/**
 * @return list<Request>
 */
function ghRequestsTo(string $method, string $suffix): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => $request->method() === $method && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', $suffix))
        ->values()
        ->all();
}

test('the first report creates a deployment, a check run and the PR comment', function (): void {
    ghFakeReportingApi();

    dispatch_sync(new ReportDeploymentStatusJob(ghStatusReport($this, DeploymentReportState::InProgress)));

    $deployment = ghRequestsTo('POST', '/deployments')[0];
    expect($deployment->data())->toMatchArray([
        'ref' => str_repeat('c', 40),
        'environment' => 'pr-42',
        'auto_merge' => false,
        'required_contexts' => [],
        'transient_environment' => true,
        'production_environment' => false,
    ]);

    $status = ghRequestsTo('POST', '/deployments/77/statuses')[0];
    expect($status->data())->toMatchArray([
        'state' => 'in_progress',
        'log_url' => 'https://launchroom.example.com/deployments/dpl_01JB7Z6X9Q3T8M5N2P4R6S8V0W',
        'environment_url' => 'https://web-pr-42.apps.example.com',
    ]);

    $checkRun = ghRequestsTo('POST', '/check-runs')[0];
    expect($checkRun->data())->toMatchArray([
        'name' => 'launchroom: web (pr-42)',
        'head_sha' => str_repeat('c', 40),
        'external_id' => 'dpl_01JB7Z6X9Q3T8M5N2P4R6S8V0W',
        'status' => 'in_progress',
        'details_url' => 'https://launchroom.example.com/deployments/dpl_01JB7Z6X9Q3T8M5N2P4R6S8V0W',
    ])->and($checkRun->data())->not->toHaveKey('conclusion');

    $comment = ghRequestsTo('POST', '/issues/42/comments')[0];
    expect($comment['body'])->toContain(GitHubDeploymentReporter::COMMENT_MARKER)
        ->toContain('| web | pr-42 | Deploying');

    $record = GitHubDeploymentReport::query()->sole();
    expect($record->github_deployment_id)->toBe(77)
        ->and($record->check_run_id)->toBe(88)
        ->and(GitHubPullRequestComment::query()->sole()->comment_id)->toBe(99);

    // Reporting tokens can't read code.
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request->data()['repository_ids'] === [555]
        && ! array_key_exists('contents', $request->data()['permissions']));
});

test('later reports update the same deployment, check run and comment', function (): void {
    ghFakeReportingApi();

    $reporter = resolve(GitHubDeploymentReporter::class);
    $reporter->report(ghStatusReport($this, DeploymentReportState::InProgress));
    $reporter->report(ghStatusReport($this, DeploymentReportState::Success));

    expect(ghRequestsTo('POST', '/deployments'))->toHaveCount(1)
        ->and(ghRequestsTo('POST', '/check-runs'))->toHaveCount(1)
        ->and(ghRequestsTo('POST', '/issues/42/comments'))->toHaveCount(1);

    expect(ghRequestsTo('POST', '/deployments/77/statuses')[1]->data()['state'])->toBe('success');

    $update = ghRequestsTo('PATCH', '/check-runs/88')[0]->data();
    expect($update['status'])->toBe('completed')
        ->and($update['conclusion'])->toBe('success')
        ->and($update)->toHaveKey('completed_at');

    $comment = ghRequestsTo('PATCH', '/issues/comments/99')[0]['body'];
    expect($comment)->toContain('| web | pr-42 | Ready')
        ->toContain('[Visit](https://web-pr-42.apps.example.com)');
});

test('the PR comment keeps one row per service', function (): void {
    ghFakeReportingApi();
    $reporter = resolve(GitHubDeploymentReporter::class);

    $reporter->report(ghStatusReport($this, DeploymentReportState::Success));
    $reporter->report(ghStatusReport($this, DeploymentReportState::Failure, [
        'deploymentKey' => 'dpl_01JB7Z6X9Q3T8M5N2P4R6S8V0X',
        'serviceName' => 'api',
        'description' => 'Build failed: pnpm lockfile is out of date',
    ]));

    $body = ghRequestsTo('PATCH', '/issues/comments/99')[0]['body'];
    expect($body)->toContain('| api | pr-42 | Failed')
        ->toContain('| web | pr-42 | Ready');

    $failedCheck = ghRequestsTo('POST', '/check-runs')[1]->data();
    expect($failedCheck['conclusion'])->toBe('failure')
        ->and($failedCheck['output']['summary'])->toContain('pnpm lockfile');
});

test('a deleted PR comment is recreated', function (): void {
    ghFakeReportingApi();
    Http::fake(['https://api.github.com/repos/octo-org/web/issues/comments/5' => Http::response(['message' => 'Not Found'], 404)]);
    GitHubPullRequestComment::query()->create([
        'team_id' => $this->team->id,
        'github_installation_id' => $this->installation->id,
        'github_repo_id' => 555,
        'pr_number' => 42,
        'comment_id' => 5,
    ]);

    resolve(GitHubDeploymentReporter::class)->report(ghStatusReport($this, DeploymentReportState::Queued));

    expect(GitHubPullRequestComment::query()->sole()->comment_id)->toBe(99);
});

test('reporting failures are logged and never thrown', function (): void {
    Log::spy();
    Http::fake([
        'https://api.github.com/app/installations/100/access_tokens' => GitHubFake::tokenResponse('ghs_secret_token'),
        'https://api.github.com/*' => Http::response(['message' => 'Server Error'], 500),
    ]);

    dispatch_sync(new ReportDeploymentStatusJob(ghStatusReport($this, DeploymentReportState::Success)));

    Log::shouldHaveReceived('warning')->times(3)->withArgs(fn (string $message, array $context): bool => ! str_contains(json_encode($context), 'ghs_secret_token'));
});

test('reports for another team’s installation do nothing', function (): void {
    Http::fake();

    resolve(GitHubDeploymentReporter::class)->report(ghStatusReport($this, DeploymentReportState::Success, ['teamId' => Team::factory()->create()->id]));

    Http::assertNothingSent();
});

test('comment cells escape table syntax', function (): void {
    $body = GitHubDeploymentReporter::renderComment([
        'x' => ['service' => 'a|b', 'environment' => "e\nf", 'state' => 'success', 'url' => null, 'logUrl' => 'https://l', 'sha' => str_repeat('a', 40), 'updatedAt' => ''],
    ]);

    expect($body)->toContain('| a\|b | e f | Ready');
});
