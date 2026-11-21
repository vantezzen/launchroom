<?php

declare(strict_types=1);

use App\Console\Vocabulary\EnumVocabulary;
use App\Enums\DeploymentStatus;
use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Enums\NodeStatusReason;
use App\Enums\TeamRole;

it('keeps the committed TypeScript vocabulary in step with the enums', function (): void {
    $this->artisan('launchroom:vocabulary', ['--check' => true])->assertSuccessful();
});

it('ships every case with its word, tone and shared facts', function (): void {
    $source = (new EnumVocabulary)->render();

    foreach (DeploymentStatus::cases() as $status) {
        $terminal = $status->isTerminal() ? 'true' : 'false';
        $cancellable = $status->isCancellable() ? 'true' : 'false';
        $unfinished = $status->isUnfinished() ? 'true' : 'false';
        expect($source)->toContain("{$status->value}: { label: '{$status->label()}', tone: '{$status->tone()->value}', isTerminal: {$terminal}, isCancellable: {$cancellable}, isUnfinished: {$unfinished} },");
    }

    expect($source)
        ->toContain('ready_to_hand_over: { isTerminal: false, isCancellable: true },')
        ->toContain('handing_over: { isTerminal: false, isCancellable: false },')
        ->toContain('cancelled: { isTerminal: true, isCancellable: false },')
        ->toContain("developer: { label: 'Developer', summary: '".TeamRole::Developer->summary()."', takesGrants: true },")
        ->toContain("'docker-engine': { label: 'Docker' },")
        ->toContain("critical: { label: 'Critical', rank: 4, isHighOrCritical: true },")
        ->toContain("medium: { label: 'Medium', rank: 2, isHighOrCritical: false },");
});

it('reads a move as cancellable exactly when its table allows it', function (): void {
    foreach (ControlPlaneMoveStatus::cases() as $status) {
        expect($status->isCancellable())->toBe($status->canTransitionTo(ControlPlaneMoveStatus::Cancelled));
    }
});

it('ships the named label of every node status reason', function (): void {
    $source = (new EnumVocabulary)->render();

    foreach (NodeStatusReason::cases() as $reason) {
        $notServing = $reason->isNotServing() ? 'true' : 'false';

        expect($source)->toContain("{$reason->value}: { label: '{$reason->label()}', tone: '{$reason->tone()->value}', isNotServing: {$notServing} },");
    }
});

it('words every in-flight deployment stage as Deploying with the stage as detail', function (): void {
    foreach (DeploymentStatus::inFlight() as $status) {
        expect($status->label())->toBe($status === DeploymentStatus::Ready ? 'Ready to promote' : 'Deploying');
    }

    expect(DeploymentStatus::Releasing->stage())->toBe('Running release command')
        ->and(DeploymentStatus::Active->stage())->toBe('Live');
});
