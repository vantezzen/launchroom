<?php

declare(strict_types=1);

use App\Actions\AppUpdates\CheckAppUpdates;
use App\Actions\AppUpdates\QueueAppUpdateChecks;
use App\Enums\Alerts\AlertRule;
use App\Enums\AppUpdates\UpdateCheckStatus;
use App\Enums\AppUpdates\UpdateKind;
use App\Jobs\AppUpdates\CheckAppUpdatesJob;
use App\Models\Alerts\Alert;
use App\Models\AppUpdates\AppUpdateCheck;
use App\Models\ServiceInstance;
use App\Models\Team;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AppUpdates;
use Tests\Support\Projects;

/*
 * Version checks of image services (D106, docs/04 §14.1): candidates in the
 * running tag's channel, builds behind moving tags, publish dates, the
 * template's release notes, backoff on registry errors and the team digest.
 */

beforeEach(function (): void {
    Projects::useTestKek();
    AppUpdates::fakeDns();
});

test('a check finds the newest patch, minor and major with dates and release notes', function (): void {
    AppUpdates::fakeHub(['v6.2.0', 'v6.2.4', 'v6.3.0', 'v7.0.1', 'v7.1.0-rc.1', 'latest']);
    [, , $instance] = AppUpdates::listmonk();

    $check = resolve(CheckAppUpdates::class)->handle($instance->id);

    expect($check)->toBeInstanceOf(AppUpdateCheck::class)
        ->and($check?->status)->toBe(UpdateCheckStatus::Available)
        ->and($check?->image)->toBe('docker.io/listmonk/listmonk:v6.2.0')
        ->and($check?->running_digest)->toBe(AppUpdates::RUNNING)
        ->and(array_map(static fn ($c): array => [$c->tag, $c->kind], $check?->candidates->all() ?? []))->toBe([
            ['v7.0.1', UpdateKind::Major],
            ['v6.3.0', UpdateKind::Minor],
            ['v6.2.4', UpdateKind::Patch],
        ])
        ->and($check?->candidate('v6.3.0')?->digest)->toBe(AppUpdates::digestOf('v6.3.0'))
        ->and($check?->candidate('v6.3.0')?->publishedAt)->toBe('2026-11-10T10:00:00Z')
        ->and($check?->running_published_at?->year)->toBe(2023)
        // From the Listmonk template's `releases`.
        ->and($check?->release_notes_url)->toBe('https://github.com/knadh/listmonk/releases')
        ->and($check?->next_check_at?->isAfter(now()->addHours(11)))->toBeTrue();

    // Tag lists go to the registry's API, dates to Docker Hub's tag API.
    Http::assertSent(static fn (Request $request): bool => str_starts_with($request->url(), 'https://registry-1.docker.io/v2/listmonk/listmonk/tags/list'));
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://hub.docker.com/v2/namespaces/listmonk/repositories/listmonk/tags/v6.3.0');
});

test('a running app with news raises one digest alert for the team', function (): void {
    AppUpdates::fakeHub(['v6.2.0', 'v6.3.0']);
    [$team, , $instance] = AppUpdates::listmonk();

    resolve(CheckAppUpdates::class)->handle($instance->id);

    $alert = Alert::query()->where('team_id', $team->id)->where('rule', AlertRule::UpdateAvailable->value)->sole();
    expect($alert->title)->toBe('listmonk has an update')
        ->and($alert->subject_key)->toBe('updates:'.$team->public_id)
        ->and($alert->summary)->toContain('v6.2.0 → v6.3.0');
});

test('nothing newer is current and resolves the digest', function (): void {
    AppUpdates::fakeHub(['v6.1.0', 'v6.2.0']);
    [, , $instance] = AppUpdates::listmonk();

    $check = resolve(CheckAppUpdates::class)->handle($instance->id);

    expect($check?->status)->toBe(UpdateCheckStatus::Current)
        ->and($check?->candidates->count())->toBe(0)
        ->and(Alert::query()->where('rule', AlertRule::UpdateAvailable->value)->exists())->toBeFalse();
});

test('a moving tag that names another image now offers a build named by its version', function (): void {
    AppUpdates::fakeHub(['latest', 'v6.2.0', 'v6.3.0'], ['latest' => AppUpdates::digestOf('v6.3.0')]);
    [, , $instance] = AppUpdates::listmonk(tag: 'latest');

    $check = resolve(CheckAppUpdates::class)->handle($instance->id);

    expect($check?->candidates->count())->toBe(1)
        ->and($check?->candidates[0]->kind)->toBe(UpdateKind::Build)
        ->and($check?->candidates[0]->tag)->toBe('latest')
        ->and($check?->candidates[0]->version)->toBe('v6.3.0');
});

test('a rate limit backs off, holds the registry and keeps asking nothing', function (): void {
    AppUpdates::fakeHub([], status: 429);
    [, , $instance] = AppUpdates::listmonk();

    $check = resolve(CheckAppUpdates::class)->handle($instance->id);

    expect($check?->status)->toBe(UpdateCheckStatus::Failed)
        ->and($check?->failures)->toBe(1)
        ->and($check?->reason)->toContain('limiting requests')
        ->and($check?->next_check_at?->isAfter(now()->addMinutes(50)))->toBeTrue();

    Http::fake();
    resolve(CheckAppUpdates::class)->handle($instance->id);
    Http::assertNothingSent();
});

test('a digest without a tag has nothing to compare', function (): void {
    AppUpdates::fakeHub([]);
    $team = Team::factory()->create();
    [$server] = Projects::server($team);
    $project = Projects::create($team);
    $web = Projects::image($project);
    $instance = ServiceInstance::query()->where('service_id', $web->id)->sole();
    AppUpdates::deploy($instance, $server);

    $check = resolve(CheckAppUpdates::class)->handle($instance->id);

    expect($check?->status)->toBe(UpdateCheckStatus::Unknown)
        ->and($check?->reason)->toBe('Pinned by digest only, so there is no version to compare.');
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'docker.io'));
});

test('due checks are queued for live image services only', function (): void {
    AppUpdates::fakeHub([]);
    [, , $instance] = AppUpdates::listmonk();
    Queue::fake();

    expect(resolve(QueueAppUpdateChecks::class)->handle())->toBe(1);
    Queue::assertPushed(CheckAppUpdatesJob::class, static fn (CheckAppUpdatesJob $job): bool => $job->instanceId === $instance->id);
});
