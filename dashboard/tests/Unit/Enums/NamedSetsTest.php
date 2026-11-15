<?php

declare(strict_types=1);

use App\Data\Services\DatabaseSource;
use App\Data\Services\GitSource;
use App\Data\Services\ImageSource;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\DeploymentFilter;
use App\Enums\DeploymentStatus;
use App\Enums\EnvironmentKind;
use App\Enums\ServerStatus;
use App\Enums\ServiceKind;
use App\Enums\Transfers\ImportRunStatus;

test('terminal and non-terminal sets come from the transition table and split the cases', function (): void {
    expect(BackupRunStatus::terminal())->toBe([BackupRunStatus::Succeeded, BackupRunStatus::Failed])
        ->and(BackupRunStatus::nonTerminal())->toBe([BackupRunStatus::Pending, BackupRunStatus::Running])
        ->and(ImportRunStatus::nonTerminal())->toBe([ImportRunStatus::WaitingForSafetyBackup, ImportRunStatus::Holding, ImportRunStatus::Importing]);
});

test('server sets: approved, reachable and down', function (): void {
    expect(ServerStatus::approved())->toBe([ServerStatus::Online, ServerStatus::Unreachable, ServerStatus::Offline])
        ->and(ServerStatus::reachable())->toBe([ServerStatus::Online, ServerStatus::Unreachable])
        ->and(ServerStatus::down())->toBe([ServerStatus::Unreachable, ServerStatus::Offline])
        ->and(ServerStatus::PendingApproval->isApproved())->toBeFalse()
        ->and(ServerStatus::Offline->isReachable())->toBeFalse();
});

test('deployments: unfinished excludes live ones; ready is in flight but settled', function (): void {
    expect(DeploymentStatus::unfinished())->toBe([DeploymentStatus::Queued, ...DeploymentStatus::inFlight()])
        ->and(DeploymentStatus::unfinished())->not->toContain(DeploymentStatus::Active)
        ->and(DeploymentStatus::Ready->isInFlight())->toBeTrue()
        ->and(DeploymentStatus::Ready->isSettled())->toBeTrue()
        ->and(DeploymentStatus::Active->isSettled())->toBeTrue()
        ->and(DeploymentStatus::Routing->isSettled())->toBeFalse()
        ->and(DeploymentStatus::Queued->isSettled())->toBeFalse()
        ->and(DeploymentFilter::InProgress->statuses())->toBe(DeploymentStatus::inFlight())
        ->and(DeploymentFilter::Failed->statuses())->toBe([DeploymentStatus::Failed]);
});

test('environments sort production, persistent, preview', function (): void {
    $kinds = EnvironmentKind::cases();
    usort($kinds, static fn (EnvironmentKind $a, EnvironmentKind $b): int => $a->sortOrder() <=> $b->sortOrder());

    expect($kinds)->toBe([EnvironmentKind::Production, EnvironmentKind::Persistent, EnvironmentKind::Preview]);
});

test('service kinds own their source, volume and config-file rules', function (): void {
    expect(ServiceKind::App->sourceType()?->sourceClass())->toBe(GitSource::class)
        ->and(ServiceKind::Image->sourceType()?->sourceClass())->toBe(ImageSource::class)
        ->and(ServiceKind::Database->sourceType()?->sourceClass())->toBe(DatabaseSource::class)
        ->and(ServiceKind::Worker->sourceType())->toBeNull()
        ->and(ServiceKind::Database->canMountSharedVolume())->toBeFalse()
        ->and(ServiceKind::Cron->canMountSharedVolume())->toBeTrue()
        ->and(ServiceKind::Worker->ownsVolumes())->toBeFalse()
        ->and(ServiceKind::Image->ownsVolumes())->toBeTrue();

    // docs/18 §3.2: `branch` for app, worker and cron; never for image or database.
    $withBranch = array_values(array_filter(ServiceKind::cases(), static fn (ServiceKind $kind): bool => $kind->hasConfigField('branch')));
    expect($withBranch)->toBe([ServiceKind::App, ServiceKind::Worker, ServiceKind::Cron])
        ->and(ServiceKind::App->hasConfigField('build'))->toBeTrue()
        ->and(ServiceKind::Worker->hasConfigField('build'))->toBeFalse()
        ->and(ServiceKind::Database->hasConfigField('deploy'))->toBeFalse();
});
