<?php

declare(strict_types=1);

use App\Actions\Logs\DetectRuntimeFindings;
use App\Enums\DeploymentStatus;
use App\Enums\LogSourceKind;
use App\Enums\TeamRole;
use App\Models\Deployment;
use App\Models\Placement;
use App\Models\ServiceInstance;
use App\Queries\ProjectSummaries;
use App\Queries\ServiceRuntimeFacts;
use App\Queries\TeamAttention;
use App\Support\Deployments\Failures\RuntimeFindings;
use Illuminate\Support\Facades\Cache;
use Tests\Support\DeploymentUi;
use Tests\Support\LogChunks;
use Tests\Support\Projects;

/*
 * Problems a live app logs while it serves become attention items (G12):
 * a framework refusing its domain, CSRF origins, a worker timeout.
 */

beforeEach(function (): void {
    Projects::useTestKek();
    Cache::flush();
});

function findingLine(DeploymentUi $world, Deployment $deployment, string $text, int $seq): void
{
    $placement = Placement::query()->where('deployment_id', $deployment->id)->firstOrFail();

    LogChunks::create([
        'team_id' => $world->team->id, 'server_id' => $world->server->id,
        'source_kind' => LogSourceKind::Workload, 'source_id' => $placement->workload_id, 'seq' => $seq,
        'deployment_id' => $deployment->id,
        'lines' => [
            ['time' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'stream' => 'stdout', 'text' => 'GET / 200'],
            ['time' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'stream' => 'stderr', 'text' => $text],
        ],
    ]);
}

test('lines are matched by the catalogue’s own entries', function (string $line, ?string $key): void {
    expect(RuntimeFindings::scan(['GET / 200', $line], '2026-11-17T10:00:00Z')?->key)->toBe($key);
})->with([
    'django host' => ["Invalid HTTP_HOST header: 'shop.example.com'. You may need to add 'shop.example.com' to ALLOWED_HOSTS.", 'django_disallowed_host'],
    'rails host' => ['[ActionDispatch::HostAuthorization::DefaultResponseApp] Blocked hosts: shop.example.com', 'rails_blocked_host'],
    'phoenix' => ['[error] Could not check origin for Phoenix.Socket transport.', 'phoenix_check_origin'],
    'csrf' => ['Forbidden (Origin checking failed - https://shop.example.com does not match any trusted origins.): /login/', 'csrf_origin'],
    'gunicorn' => ['[2026-11-17 10:00:00 +0000] [1] [CRITICAL] WORKER TIMEOUT (pid:12)', 'gunicorn_timeout'],
    'other errors are not findings' => ['Error: connect ECONNREFUSED 127.0.0.1:5432', null],
]);

test('a live deployment keeps its first finding, shown in the panel and in Needs you', function (): void {
    $world = DeploymentUi::make();
    $web = $world->latest($world->web);
    expect($web->status)->toBe(DeploymentStatus::Active);

    findingLine($world, $web, "Invalid HTTP_HOST header: 'shop.example.com'.", 1);
    resolve(DetectRuntimeFindings::class)->handle($world->server->id);

    findingLine($world, $web, '[CRITICAL] WORKER TIMEOUT (pid:12)', 2);
    resolve(DetectRuntimeFindings::class)->handle($world->server->id);

    $finding = $web->refresh()->runtime_finding;
    expect($finding?->key)->toBe('django_disallowed_host')
        ->and($finding?->line)->toContain('Invalid HTTP_HOST header');

    $instance = ServiceInstance::query()->whereKey($web->service_instance_id)->sole();
    expect(resolve(ServiceRuntimeFacts::class)->for($instance)?->finding?->key)->toBe('django_disallowed_host');

    $viewer = $world->member(TeamRole::Viewer);
    $items = collect(resolve(TeamAttention::class)->for($world->team, $viewer, resolve(ProjectSummaries::class)->for($world->team)));
    $item = $items->firstWhere('key', "runtime:{$web->public_id}");
    expect($item?->title)->toBe('web: Django refuses this domain')
        ->and($item?->href)->toEndWith('/logs');
});

test('a deployment that isn’t live gets no finding', function (): void {
    $world = DeploymentUi::make();
    $web = $world->latest($world->web);
    $web->forceFill(['status' => DeploymentStatus::Superseded])->save();

    findingLine($world, $web, "Invalid HTTP_HOST header: 'shop.example.com'.", 1);
    resolve(DetectRuntimeFindings::class)->handle($world->server->id);

    expect($web->refresh()->runtime_finding)->toBeNull();
});
