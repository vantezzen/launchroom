<?php

declare(strict_types=1);

use App\Actions\AppUpdates\AdvanceAppUpdate;
use App\Actions\AppUpdates\CheckAppUpdates;
use App\Actions\AppUpdates\StartAppUpdate;
use App\Actions\AppUpdates\StartAutomaticUpdates;
use App\Actions\Services\UpdateServiceSettings;
use App\Data\AppUpdates\StartAppUpdateData;
use App\Data\AppUpdates\UpdateWindowData;
use App\Data\Audit\AuditActor;
use App\Data\Services\UpdateServiceSettingsData;
use App\Enums\AppUpdates\AppUpdateStatus;
use App\Enums\AppUpdates\AutoUpdateMode;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\DeploymentFailureClass;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Models\AppUpdates\AppUpdate;
use App\Models\Backups\BackupRun;
use App\Models\Deployment;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Support\Refusal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\Support\AppUpdates;
use Tests\Support\Backups;
use Tests\Support\Projects;

/*
 * Updating an image service (D106, docs/04 §14.2-14.3): backup first, deploy
 * the pinned digest with trigger `update`, watch the probation, never
 * anything that isn't a checked candidate.
 */

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
    AppUpdates::fakeDns();
    AppUpdates::fakeHub(['v6.2.0', 'v6.2.4', 'v6.3.0', 'v7.0.1']);
});
afterEach(fn () => Date::setTestNow());

function startUpdate(ServiceInstance $instance, string $tag, bool $read = false): AppUpdate|Refusal
{
    return resolve(StartAppUpdate::class)->handle($instance, new StartAppUpdateData($tag, $read), null, AuditActor::system());
}

test('an update deploys the checked digest, watches it and succeeds', function (): void {
    [, $server, $instance] = AppUpdates::listmonk();
    resolve(CheckAppUpdates::class)->handle($instance->id);

    $update = startUpdate($instance, 'v6.3.0');

    expect($update)->toBeInstanceOf(AppUpdate::class);
    $update = $update->refresh();
    $deployment = Deployment::query()->findOrFail($update->deployment_id);

    expect($update->status)->toBe(AppUpdateStatus::Deploying)
        ->and($update->backup_note)->toContain('nothing to back up')
        ->and($update->to_reference)->toBe('docker.io/listmonk/listmonk:v6.3.0@'.AppUpdates::digestOf('v6.3.0'))
        ->and($deployment->trigger)->toBe(DeploymentTrigger::Update)
        ->and($deployment->revision?->message)->toBe('Update listmonk from v6.2.0 to v6.3.0')
        ->and($instance->service->refresh()->source->toArray()['reference'])->toBe($update->to_reference);

    AppUpdates::settle($server);
    resolve(AdvanceAppUpdate::class)->handle($update->id);

    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Active)
        ->and($deployment->probation_until)->not->toBeNull()
        ->and($update->refresh()->status)->toBe(AppUpdateStatus::Watching);

    Date::setTestNow(now()->addMinutes(11));
    resolve(AdvanceAppUpdate::class)->handle($update->id);

    expect($update->refresh()->status)->toBe(AppUpdateStatus::Succeeded)
        ->and($update->finished_at)->not->toBeNull();
});

test('only a checked candidate can be the target', function (): void {
    [, , $instance] = AppUpdates::listmonk();
    resolve(CheckAppUpdates::class)->handle($instance->id);

    $refusal = startUpdate($instance, 'v9.9.9');

    expect($refusal)->toBeInstanceOf(Refusal::class)
        ->and($refusal->field)->toBe('tag')
        ->and(AppUpdate::query()->exists())->toBeFalse();
});

test('a major needs the release notes read', function (): void {
    [, , $instance] = AppUpdates::listmonk();
    resolve(CheckAppUpdates::class)->handle($instance->id);

    $refusal = startUpdate($instance, 'v7.0.1');

    expect($refusal)->toBeInstanceOf(Refusal::class)
        ->and($refusal->field)->toBe('releaseNotesRead')
        ->and(startUpdate($instance, 'v7.0.1', read: true))->toBeInstanceOf(AppUpdate::class);
});

test('other staged changes keep the update from going live with them', function (): void {
    [, , $instance] = AppUpdates::listmonk();
    resolve(CheckAppUpdates::class)->handle($instance->id);
    resolve(UpdateServiceSettings::class)->handle($instance->refresh(), UpdateServiceSettingsData::from(['deploy' => [...$instance->deploy->toArray(), 'drainSeconds' => 20]]));

    $refusal = startUpdate($instance->refresh(), 'v6.3.0');

    expect($refusal)->toBeInstanceOf(Refusal::class)
        ->and($refusal->isConflict())->toBeTrue()
        ->and($refusal->message)->toBe('Deploy or discard the other staged change first, so the update goes live alone.');
});

test('a failed deployment fails the update and the old version keeps running', function (): void {
    [, $server, $instance] = AppUpdates::listmonk();
    resolve(CheckAppUpdates::class)->handle($instance->id);
    $before = $instance->active_deployment_id;

    $update = startUpdate($instance, 'v6.2.4');
    $deployment = Deployment::query()->findOrFail($update->refresh()->deployment_id);
    $deployment->fail(DeploymentFailureClass::RuntimeError, 'The app exited with code 1.');

    resolve(AdvanceAppUpdate::class)->handle($update->id);

    expect($update->refresh()->status)->toBe(AppUpdateStatus::Failed)
        ->and($update->message)->toBe('v6.2.4 didn’t go live: The app exited with code 1. v6.2.0 keeps running.')
        ->and($instance->refresh()->active_deployment_id)->toBe($before);
});

test('the data is backed up first, and a failed backup changes nothing', function (): void {
    [$team, , $instance, $postgres] = AppUpdates::listmonk(withDatabase: true);
    Backups::destination($team, User::factory()->create(), makeDefault: true);
    resolve(CheckAppUpdates::class)->handle($instance->id);
    $source = $instance->service->source->toArray()['reference'];

    $update = startUpdate($instance, 'v6.3.0')->refresh();
    $run = BackupRun::query()->whereIn('id', $update->backup_run_ids)->sole();

    expect($update->status)->toBe(AppUpdateStatus::BackingUp)
        ->and($run->service_instance_id)->toBe($postgres?->id)
        ->and($run->reason)->toBe(BackupReason::PreUpdate);

    $run->transitionTo(BackupRunStatus::Failed, 'bucket unreachable');
    resolve(AdvanceAppUpdate::class)->handle($update->id);

    expect($update->refresh()->status)->toBe(AppUpdateStatus::Failed)
        ->and($update->message)->toBe('The backup of postgres failed, so launchroom didn’t update. v6.2.0 keeps running.')
        ->and($instance->service->refresh()->source->toArray()['reference'])->toBe($source);
});

test('after a successful backup the update deploys', function (): void {
    [$team, , $instance] = AppUpdates::listmonk(withDatabase: true);
    Backups::destination($team, User::factory()->create(), makeDefault: true);
    resolve(CheckAppUpdates::class)->handle($instance->id);

    $update = startUpdate($instance, 'v6.3.0')->refresh();
    BackupRun::query()->whereIn('id', $update->backup_run_ids)->each(static function (BackupRun $run): void {
        $run->transitionTo(BackupRunStatus::Succeeded);
    });
    resolve(AdvanceAppUpdate::class)->handle($update->id);

    expect($update->refresh()->status)->toBe(AppUpdateStatus::Deploying)
        ->and($update->deployment_id)->not->toBeNull();
});

test('automatic updates take allowed kinds in the window once a release has settled', function (): void {
    [, , $instance] = AppUpdates::listmonk();
    $instance->forceFill(['auto_update' => AutoUpdateMode::Patch, 'auto_update_window' => new UpdateWindowData([1, 2, 3, 4, 5, 6, 7], '00:00', 12, 'UTC')])->save();
    resolve(CheckAppUpdates::class)->handle($instance->id, CarbonImmutable::parse('2026-11-20 01:00:00'));

    // Too fresh: first seen an hour ago.
    expect(resolve(StartAutomaticUpdates::class)->handle(CarbonImmutable::parse('2026-11-20 02:00:00')))->toBe(0);

    // Outside the window.
    expect(resolve(StartAutomaticUpdates::class)->handle(CarbonImmutable::parse('2026-11-23 13:00:00')))->toBe(0);

    expect(resolve(StartAutomaticUpdates::class)->handle(CarbonImmutable::parse('2026-11-23 02:00:00')))->toBe(1);
    $update = AppUpdate::query()->sole();
    expect($update->automatic)->toBeTrue()
        ->and($update->to_version)->toBe('v6.2.4')
        ->and($update->actor_id)->toBeNull();
});

test('automatic updates never take a major', function (): void {
    AppUpdates::fakeHub(['v6.2.0', 'v7.0.1']);
    [, , $instance] = AppUpdates::listmonk();
    $instance->forceFill(['auto_update' => AutoUpdateMode::Minor, 'auto_update_window' => new UpdateWindowData([1, 2, 3, 4, 5, 6, 7], '00:00', 12, 'UTC')])->save();
    resolve(CheckAppUpdates::class)->handle($instance->id, CarbonImmutable::parse('2026-11-01 01:00:00'));

    expect(resolve(StartAutomaticUpdates::class)->handle(CarbonImmutable::parse('2026-11-23 02:00:00')))->toBe(0);
});
