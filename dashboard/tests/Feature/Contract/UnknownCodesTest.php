<?php

declare(strict_types=1);

use App\Data\Protocol\V1\AccessResult;
use App\Data\Protocol\V1\SessionResult;
use App\Data\Protocol\V1\TaskFailure;
use App\Data\Protocol\V1\WorkloadEvent;
use App\Data\Protocol\V1\WorkloadStatus;
use App\Enums\PortHint;
use App\Enums\Sessions\StartFailure;
use App\Enums\TaskFailureClass;
use Illuminate\Validation\ValidationException;

/*
| Explanation codes from a newer launchroomd read as none instead of refusing
| the whole report or completion (docs/03 §10 "Unknown codes"); a value
| outside the code grammar is still refused.
*/

/**
 * @return array<string, mixed>
 */
function unknownCodesWorkloadStatus(string $portHint): array
{
    return ['id' => 'wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK', 'phase' => 'running', 'observedAt' => '2026-11-15T12:00:00Z', 'restartCount' => 0, 'portHint' => $portHint];
}

test('a known port hint is the enum, an unknown one reads as none', function (): void {
    expect(WorkloadStatus::validateAndCreate(unknownCodesWorkloadStatus('loopback_only'))->portHint)->toBe(PortHint::LoopbackOnly)
        ->and(WorkloadStatus::validateAndCreate(unknownCodesWorkloadStatus('listens_on_ipv6_only'))->portHint)->toBeNull();
});

test('a port hint outside the code grammar is refused', function (): void {
    WorkloadStatus::validateAndCreate(unknownCodesWorkloadStatus('Not A Code'));
})->throws(ValidationException::class);

test('unknown session and access start failures read as none', function (): void {
    $session = SessionResult::validateAndCreate(['endReason' => 'refused', 'bytesIn' => 0, 'bytesOut' => 0, 'failure' => 'brand_new_reason']);
    $known = SessionResult::validateAndCreate(['endReason' => 'refused', 'bytesIn' => 0, 'bytesOut' => 0, 'failure' => 'no_shell']);

    expect($session->failure)->toBeNull()
        ->and($known->failure)->toBe(StartFailure::NoShell)
        ->and(AccessResult::validateAndCreate(['endReason' => 'refused', 'connections' => 0, 'authFailures' => 0, 'bytesIn' => 0, 'bytesOut' => 0, 'failure' => 'brand_new_reason'])->failure)->toBeNull();
});

test('an unknown workload event reason reads as none', function (): void {
    $event = WorkloadEvent::validateAndCreate(['seq' => 1, 'workload' => 'wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK', 'at' => '2026-11-15T12:00:00Z', 'kind' => 'removed', 'reason' => 'brand_new_reason']);

    expect($event->reason)->toBeNull();
});

test('an unknown task failure class is kept and counts as an internal error', function (): void {
    $failure = TaskFailure::validateAndCreate(['class' => 'brand_new_class']);

    expect($failure->class)->toBe('brand_new_class')
        ->and($failure->failureClass())->toBe(TaskFailureClass::InternalError);
});
