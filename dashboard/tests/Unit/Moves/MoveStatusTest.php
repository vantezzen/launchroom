<?php

declare(strict_types=1);

use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Enums\Moves\MoveItemStatus;
use App\Enums\Moves\ServerMoveStatus;
use App\Services\Moves\MovePlanner;
use App\Services\Moves\MoveToken;

/*
| The state machines of server moves and the control-plane move (docs/14
| §13), and the move token (§13.4).
*/

test('a stateful item can only go back after the cutover, never once it is done', function (): void {
    // Before the cutover a failure or cancel changes nothing on the source.
    expect(MoveItemStatus::Exporting->canTransitionTo(MoveItemStatus::Failed))->toBeTrue()
        ->and(MoveItemStatus::Exporting->canTransitionTo(MoveItemStatus::RollingBack))->toBeFalse()
        // After it, the only ways out are forward or back.
        ->and(MoveItemStatus::Preparing->canTransitionTo(MoveItemStatus::Failed))->toBeFalse()
        ->and(MoveItemStatus::Preparing->canTransitionTo(MoveItemStatus::RollingBack))->toBeTrue()
        ->and(MoveItemStatus::Importing->canTransitionTo(MoveItemStatus::Cancelled))->toBeFalse()
        ->and(MoveItemStatus::RollingBack->canTransitionTo(MoveItemStatus::Failed))->toBeTrue()
        ->and(MoveItemStatus::RollingBack->canTransitionTo(MoveItemStatus::RolledBack))->toBeTrue()
        // Done is done: its writers already write on the target.
        ->and(MoveItemStatus::Done->isTerminal())->toBeTrue()
        ->and(MoveItemStatus::Failed->canTransitionTo(MoveItemStatus::Pending))->toBeTrue()
        ->and(MoveItemStatus::Preparing->isPastCutover())->toBeTrue()
        ->and(MoveItemStatus::Exporting->isPastCutover())->toBeFalse();

    foreach ([MoveItemStatus::Stopping, MoveItemStatus::Exporting, MoveItemStatus::Preparing, MoveItemStatus::Importing, MoveItemStatus::RollingBack] as $status) {
        expect($status->holdsWriters())->toBeTrue();
    }

    foreach ([MoveItemStatus::Pending, MoveItemStatus::Building, MoveItemStatus::BackingUp, MoveItemStatus::Done, MoveItemStatus::Failed] as $status) {
        expect($status->holdsWriters())->toBeFalse();
    }
});

test('a move is ready once everything moved, and ends retired or cancelled', function (): void {
    expect(ServerMoveStatus::Running->canTransitionTo(ServerMoveStatus::Ready))->toBeTrue()
        ->and(ServerMoveStatus::Running->canTransitionTo(ServerMoveStatus::Completed))->toBeFalse()
        ->and(ServerMoveStatus::Ready->canTransitionTo(ServerMoveStatus::Running))->toBeTrue()
        ->and(ServerMoveStatus::Ready->canTransitionTo(ServerMoveStatus::Completed))->toBeTrue()
        ->and(ServerMoveStatus::Cancelling->canTransitionTo(ServerMoveStatus::Cancelled))->toBeTrue()
        ->and(ServerMoveStatus::Completed->isTerminal())->toBeTrue()
        ->and(ServerMoveStatus::Cancelled->isOpen())->toBeFalse();
});

test('the control-plane move can be cancelled until the hand-over and aborted until it completes', function (): void {
    foreach ([ControlPlaneMoveStatus::WaitingForServer, ControlPlaneMoveStatus::Claimed, ControlPlaneMoveStatus::Joining, ControlPlaneMoveStatus::Joined, ControlPlaneMoveStatus::Moving, ControlPlaneMoveStatus::ReadyToHandOver] as $status) {
        expect($status->canTransitionTo(ControlPlaneMoveStatus::Cancelled))->toBeTrue();
    }

    expect(ControlPlaneMoveStatus::HandingOver->canTransitionTo(ControlPlaneMoveStatus::Cancelled))->toBeFalse()
        ->and(ControlPlaneMoveStatus::HandoverReady->canTransitionTo(ControlPlaneMoveStatus::ReadyToHandOver))->toBeTrue()
        ->and(ControlPlaneMoveStatus::HandoverReady->canTransitionTo(ControlPlaneMoveStatus::HandedOver))->toBeTrue()
        // The new dashboard's restored copy caught the move while handing over.
        ->and(ControlPlaneMoveStatus::HandingOver->canTransitionTo(ControlPlaneMoveStatus::HandedOver))->toBeTrue()
        ->and(ControlPlaneMoveStatus::HandedOver->isTerminal())->toBeTrue()
        ->and(ControlPlaneMoveStatus::HandingOver->freezes())->toBeTrue()
        ->and(ControlPlaneMoveStatus::ReadyToHandOver->freezes())->toBeFalse();
});

test('a move token carries the address, the move, a secret and the pinned key, and nothing else parses', function (): void {
    $key = 'ed25519:'.base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
    $issued = MoveToken::issue('https://dash.example.com', 'cpm_01J9Z8X7W6V5T4S3R2Q1P0N9M8', $key);
    $parsed = MoveToken::parse($issued['token']);

    expect($parsed)->not->toBeNull()
        ->and($parsed['url'] ?? null)->toBe('https://dash.example.com')
        ->and($parsed['move'] ?? null)->toBe('cpm_01J9Z8X7W6V5T4S3R2Q1P0N9M8')
        ->and($parsed['signingKey'] ?? null)->toBe($key)
        ->and(MoveToken::hashSecret($parsed['secret'] ?? ''))->toBe($issued['hash'])
        ->and(MoveToken::parse('lrmv1.x.y.z'))->toBeNull()
        ->and(MoveToken::parse(str_replace('lrmv1', 'lrmv2', $issued['token'])))->toBeNull()
        ->and(MoveToken::parse(str_replace('cpm_', 'srv_', $issued['token'])))->toBeNull();
});

test('the claim code depends on the new machine’s key', function (): void {
    $a = MoveToken::claimCode('cpm_01J9Z8X7W6V5T4S3R2Q1P0N9M8', 'x25519:'.base64_encode(random_bytes(32)));
    $b = MoveToken::claimCode('cpm_01J9Z8X7W6V5T4S3R2Q1P0N9M8', 'x25519:'.base64_encode(random_bytes(32)));

    expect($a)->toMatch('/^[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$/')
        ->and($a)->not->toBe($b);
});

test('the downtime estimate copies the data out and in at the assumed rate', function (): void {
    expect(MovePlanner::downtimeFor(0))->toBe(MovePlanner::OVERHEAD_SECONDS)
        ->and(MovePlanner::downtimeFor(1_000_000_000))->toBe(MovePlanner::OVERHEAD_SECONDS + 100)
        ->and(MovePlanner::size(1_500_000_000))->toBe('1.5 GB');
});
