<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\Previews\PreviewStatus;
use App\Enums\Updates\FleetUpdateStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Deployment;
use App\Models\Updates\FleetUpdate;
use Illuminate\Support\Facades\DB;

test('a transition caps its note at the shared limit and stamps the model', function (): void {
    $update = FleetUpdate::query()->create(['to_version' => '1.2.3', 'daemon_image' => 'ghcr.io/vantezzen/launchroom-daemon:1.2.3']);

    $update->transitionTo(FleetUpdateStatus::Failed, str_repeat('x', 5000));

    $update->refresh();
    expect($update->status)->toBe(FleetUpdateStatus::Failed)
        ->and(mb_strlen((string) $update->error))->toBe(FleetUpdate::NOTE_LIMIT)
        ->and($update->finished_at)->not->toBeNull();
});

test('a transition outside the table is refused before anything is saved', function (): void {
    $update = FleetUpdate::query()->create(['to_version' => '1.2.3', 'daemon_image' => 'ghcr.io/vantezzen/launchroom-daemon:1.2.3']);
    $update->transitionTo(FleetUpdateStatus::Succeeded);

    expect(fn () => $update->transitionTo(FleetUpdateStatus::Failed, 'late'))->toThrow(InvalidStatusTransition::class)
        ->and($update->refresh()->error)->toBeNull();
});

test('every note column is as wide as the shared limit', function (string $table, string $column): void {
    $width = DB::scalar('select character_maximum_length from information_schema.columns where table_name = ? and column_name = ?', [$table, $column]);

    expect($width === null || $width >= FleetUpdate::NOTE_LIMIT)->toBeTrue("{$table}.{$column} is {$width} wide");
})->with([
    ['alerts', 'resolve_reason'],
    ['alert_deliveries', 'error'],
    ['previews', 'status_reason'],
    ['git_connections', 'status_reason'],
    ['webhook_deliveries', 'error'],
    ['backup_runs', 'error'],
    ['server_move_items', 'message'],
]);

test('a deployment fails only with a failure class', function (): void {
    $deployment = new Deployment;
    $deployment->status = DeploymentStatus::Building;

    expect(fn () => $deployment->transitionTo(DeploymentStatus::Failed))->toThrow(LogicException::class);
});

test('a preview may re-enter its status with a new reason', function (PreviewStatus $status): void {
    expect($status->canTransitionTo($status))->toBeTrue();
})->with(PreviewStatus::cases());
