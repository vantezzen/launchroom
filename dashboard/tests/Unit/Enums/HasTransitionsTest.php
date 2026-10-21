<?php

declare(strict_types=1);

use App\Exceptions\InvalidStatusTransition;
use Tests\Fixtures\FakeDeploymentStatus;
use Tests\Fixtures\OtherStatus;

test('allows transitions listed in the table', function (FakeDeploymentStatus $from, FakeDeploymentStatus $to): void {
    expect($from->canTransitionTo($to))->toBeTrue();

    $from->assertCanTransitionTo($to);
})->with([
    [FakeDeploymentStatus::Queued, FakeDeploymentStatus::Building],
    [FakeDeploymentStatus::Queued, FakeDeploymentStatus::Cancelled],
    [FakeDeploymentStatus::Building, FakeDeploymentStatus::Active],
    [FakeDeploymentStatus::Building, FakeDeploymentStatus::Failed],
]);

test('rejects transitions not in the table', function (FakeDeploymentStatus $from, FakeDeploymentStatus $to): void {
    expect($from->canTransitionTo($to))->toBeFalse()
        ->and(fn () => $from->assertCanTransitionTo($to))
        ->toThrow(InvalidStatusTransition::class, "Cannot change status from \"{$from->value}\" to \"{$to->value}\".");
})->with([
    'skipping a step' => [FakeDeploymentStatus::Queued, FakeDeploymentStatus::Active],
    'self transition' => [FakeDeploymentStatus::Building, FakeDeploymentStatus::Building],
    'leaving a terminal status' => [FakeDeploymentStatus::Failed, FakeDeploymentStatus::Queued],
    'going backwards' => [FakeDeploymentStatus::Building, FakeDeploymentStatus::Queued],
]);

test('never transitions to a status of another enum, even with the same value', function (): void {
    expect(FakeDeploymentStatus::Queued->canTransitionTo(OtherStatus::Queued))->toBeFalse()
        ->and(OtherStatus::Queued->canTransitionTo(FakeDeploymentStatus::Queued))->toBeFalse();
});

test('the exception carries both statuses', function (): void {
    try {
        FakeDeploymentStatus::Active->assertCanTransitionTo(FakeDeploymentStatus::Queued);
        $this->fail('Expected exception');
    } catch (InvalidStatusTransition $e) {
        expect($e->from)->toBe(FakeDeploymentStatus::Active)
            ->and($e->to)->toBe(FakeDeploymentStatus::Queued)
            ->and($e)->toBeInstanceOf(DomainException::class);
    }
});

test('statuses without outgoing transitions are terminal', function (): void {
    expect(FakeDeploymentStatus::Active->isTerminal())->toBeTrue()
        ->and(FakeDeploymentStatus::Queued->isTerminal())->toBeFalse();
});
