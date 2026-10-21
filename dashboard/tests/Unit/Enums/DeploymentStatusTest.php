<?php

declare(strict_types=1);

use App\Enums\ChangeEffect;
use App\Enums\DeploymentStatus;
use App\Enums\ServiceKind;

test('the happy path is a chain of allowed transitions', function (): void {
    $path = [DeploymentStatus::Queued, DeploymentStatus::Building, DeploymentStatus::Releasing, DeploymentStatus::RollingOut, DeploymentStatus::HealthChecking, DeploymentStatus::Routing, DeploymentStatus::Active, DeploymentStatus::Superseded];
    $counter = count($path);

    for ($i = 1; $i < $counter; $i++) {
        expect($path[$i - 1]->canTransitionTo($path[$i]))->toBeTrue("{$path[$i - 1]->value} → {$path[$i]->value}");
    }
});

test('build and release are optional', function (): void {
    expect(DeploymentStatus::Queued->canTransitionTo(DeploymentStatus::RollingOut))->toBeTrue()
        ->and(DeploymentStatus::Queued->canTransitionTo(DeploymentStatus::Releasing))->toBeTrue()
        ->and(DeploymentStatus::Building->canTransitionTo(DeploymentStatus::RollingOut))->toBeTrue();
});

test('terminal statuses go nowhere; active only to superseded', function (): void {
    foreach ([DeploymentStatus::Failed, DeploymentStatus::Cancelled, DeploymentStatus::Superseded] as $status) {
        expect($status->isTerminal())->toBeTrue();
    }

    expect(DeploymentStatus::Active->allowedTransitions())->toBe([DeploymentStatus::Superseded]);
});

test('every in-flight status can fail; routing can not be cancelled', function (): void {
    foreach (DeploymentStatus::inFlight() as $status) {
        expect($status->canTransitionTo(DeploymentStatus::Failed))->toBeTrue($status->value);
    }

    expect(DeploymentStatus::Routing->canTransitionTo(DeploymentStatus::Cancelled))->toBeFalse()
        ->and(DeploymentStatus::HealthChecking->canTransitionTo(DeploymentStatus::Active))->toBeFalse()
        ->and(DeploymentStatus::Active->canTransitionTo(DeploymentStatus::Failed))->toBeFalse();
});

test('no status can transition to queued or to itself', function (): void {
    foreach (DeploymentStatus::cases() as $from) {
        expect($from->canTransitionTo(DeploymentStatus::Queued))->toBeFalse()
            ->and($from->canTransitionTo($from))->toBeFalse();
    }
});

test('change effects rank and collapse rebuilds for never-built kinds', function (): void {
    expect(ChangeEffect::Restart->max(ChangeEffect::Rebuild))->toBe(ChangeEffect::Rebuild)
        ->and(ChangeEffect::Route->max(ChangeEffect::None))->toBe(ChangeEffect::Route)
        ->and(ChangeEffect::Rebuild->forKind(ServiceKind::Database))->toBe(ChangeEffect::Restart)
        ->and(ChangeEffect::Rebuild->forKind(ServiceKind::App))->toBe(ChangeEffect::Rebuild)
        ->and(ChangeEffect::Route->needsDeployment())->toBeFalse()
        ->and(ChangeEffect::New->needsDeployment())->toBeTrue();
});
