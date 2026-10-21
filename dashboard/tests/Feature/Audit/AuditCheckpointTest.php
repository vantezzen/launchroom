<?php

declare(strict_types=1);

use App\Actions\Audit\CreateAuditCheckpoint;
use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Audit\VerifyAuditChain;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\Audit\AuditChainProblem;
use App\Enums\AuditAction;
use App\Models\AuditCheckpoint;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Services\Crypto\Signer;
use App\Support\AuditChain;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
| Tamper evidence of the audit log (docs/09 R34, protocol/SIGNING.md §7):
| signed checkpoints, and verification that names what was done.
*/

function auditEntries(int $count): void
{
    $record = resolve(RecordAuditEvent::class);

    for ($i = 0; $i < $count; $i++) {
        $record->handle(new AuditEntryData(AuditAction::MemberInvited, AuditActor::system(), meta: ['email' => "u{$i}@example.com", 'role' => 'viewer']));
    }
}

/**
 * @param  Closure(): void  $change
 */
function withoutAuditTriggers(Closure $change): void
{
    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update_delete');
    DB::statement('ALTER TABLE audit_checkpoints DISABLE TRIGGER audit_checkpoints_no_update_delete');
    $change();
    DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_update_delete');
    DB::statement('ALTER TABLE audit_checkpoints ENABLE TRIGGER audit_checkpoints_no_update_delete');
}

/** @return list<int> */
function auditIds(): array
{
    return AuditEvent::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
}

test('a checkpoint signs the newest entry with the control-plane key over the domain-separated message', function (): void {
    auditEntries(3);
    $checkpoint = resolve(CreateAuditCheckpoint::class)->handle();
    $latest = AuditEvent::query()->orderByDesc('id')->firstOrFail();

    expect($checkpoint)->not->toBeNull()
        ->and($checkpoint->event_id)->toBe($latest->id)
        ->and($checkpoint->event_hash)->toBe($latest->hash)
        ->and($checkpoint->event_count)->toBe(3)
        ->and($checkpoint->kid)->toBe(TestCase::SIGNING_KID)
        ->and($checkpoint->instance_id)->toBe(InstanceSettings::current()->instance_id);

    $message = "launchroom-audit-checkpoint-v1\n".json_encode([
        'count' => 3,
        'createdAt' => $checkpoint->created_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
        'eventId' => $latest->id,
        'hash' => $latest->hash,
        'instance' => $checkpoint->instance_id,
    ], JSON_UNESCAPED_SLASHES);
    $public = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(TestCase::signingSeed()));

    expect(sodium_crypto_sign_verify_detached((string) base64_decode($checkpoint->signature, true), $message, $public))->toBeTrue()
        // Nothing new: no second checkpoint.
        ->and(resolve(CreateAuditCheckpoint::class)->handle())->toBeNull();

    auditEntries(2);
    expect(resolve(CreateAuditCheckpoint::class)->handle()?->event_count)->toBe(5);
});

test('the signer refuses to sign anything that is not a checkpoint message', function (): void {
    expect(fn () => resolve(Signer::class)->signAuditCheckpoint('{"apiVersion":"launchroom.vantezzen.io/v1"}'))
        ->toThrow(InvalidArgumentException::class);
});

test('checkpoints are append-only in the database', function (): void {
    auditEntries(1);
    resolve(CreateAuditCheckpoint::class)->handle();

    expect(fn () => DB::table('audit_checkpoints')->update(['event_count' => 9]))->toThrow(QueryException::class);
});

test('an intact chain with checkpoints verifies, fully and from the last checkpoint', function (): void {
    auditEntries(3);
    resolve(CreateAuditCheckpoint::class)->handle();
    auditEntries(2);

    $full = resolve(VerifyAuditChain::class)->handle();
    $incremental = resolve(VerifyAuditChain::class)->handle(fromCheckpoint: true);

    expect($full->valid)->toBeTrue()
        ->and($full->checked)->toBe(5)
        ->and($full->checkpoints)->toBe(1)
        ->and($full->verifiedUpTo)->not->toBeNull()
        ->and($incremental->valid)->toBeTrue()
        ->and($incremental->incremental)->toBeTrue()
        ->and($incremental->checked)->toBe(2);
});

test('an edited entry is named', function (): void {
    auditEntries(3);
    $ids = auditIds();

    withoutAuditTriggers(fn () => DB::table('audit_events')->where('id', $ids[1])->update(['meta' => json_encode(['role' => 'owner'])]));

    $result = resolve(VerifyAuditChain::class)->handle();

    expect($result->valid)->toBeFalse()
        ->and($result->problem)->toBe(AuditChainProblem::Edited)
        ->and($result->firstInvalidId)->toBe($ids[1])
        ->and($result->message)->toContain('changed');
});

test('a deleted entry breaks the link of the next one', function (): void {
    auditEntries(3);
    $ids = auditIds();

    withoutAuditTriggers(fn () => DB::table('audit_events')->where('id', $ids[1])->delete());

    $result = resolve(VerifyAuditChain::class)->handle();

    expect($result->problem)->toBe(AuditChainProblem::BrokenLink)
        ->and($result->firstInvalidId)->toBe($ids[2]);
});

test('reordered entries break the chain', function (): void {
    auditEntries(3);
    [$a, $b, $c] = auditIds();

    // Swap the positions of the second and third entry.
    withoutAuditTriggers(function () use ($b, $c): void {
        DB::table('audit_events')->where('id', $b)->update(['id' => 999_999]);
        DB::table('audit_events')->where('id', $c)->update(['id' => $b]);
        DB::table('audit_events')->where('id', 999_999)->update(['id' => $c]);
    });

    $result = resolve(VerifyAuditChain::class)->handle();

    expect($result->valid)->toBeFalse()
        ->and($result->problem)->toBe(AuditChainProblem::BrokenLink)
        ->and($result->firstInvalidId)->toBe($b);
});

test('entries cut from the end behind a checkpoint are detected', function (): void {
    auditEntries(4);
    resolve(CreateAuditCheckpoint::class)->handle();
    $ids = auditIds();

    withoutAuditTriggers(fn () => DB::table('audit_events')->whereIn('id', [$ids[2], $ids[3]])->delete());

    $result = resolve(VerifyAuditChain::class)->handle();

    expect($result->valid)->toBeFalse()
        ->and($result->problem)->toBe(AuditChainProblem::Truncated)
        ->and($result->firstInvalidId)->toBe($ids[1])
        ->and($result->message)->toContain("after #{$ids[1]}");

    // The incremental check can't start at a checkpoint whose entry is gone, so it checks everything.
    expect(resolve(VerifyAuditChain::class)->handle(fromCheckpoint: true)->problem)->toBe(AuditChainProblem::Truncated);
});

test('a checkpoint signed with another key is a forgery', function (): void {
    auditEntries(2);
    $latest = AuditEvent::query()->orderByDesc('id')->firstOrFail();
    $at = CarbonImmutable::now()->utc();
    $foreign = Signer::fromSeed(TestCase::SIGNING_KID, str_repeat("\x07", 32));

    AuditCheckpoint::query()->create([
        'event_id' => $latest->id, 'event_hash' => $latest->hash, 'event_count' => 2, 'instance_id' => null,
        'kid' => TestCase::SIGNING_KID, 'created_at' => $at,
        'signature' => $foreign->signAuditCheckpoint(AuditChain::checkpointMessage(2, $at, $latest->id, $latest->hash, null)),
    ]);

    $result = resolve(VerifyAuditChain::class)->handle();

    expect($result->valid)->toBeFalse()
        ->and($result->problem)->toBe(AuditChainProblem::ForgedCheckpoint);
});

test('a checkpoint with changed fields no longer verifies', function (): void {
    auditEntries(3);
    $checkpoint = resolve(CreateAuditCheckpoint::class)->handle();
    expect($checkpoint)->not->toBeNull();

    withoutAuditTriggers(fn () => DB::table('audit_checkpoints')->where('id', $checkpoint?->id)->update(['event_count' => 2]));

    expect(resolve(VerifyAuditChain::class)->handle()->problem)->toBe(AuditChainProblem::ForgedCheckpoint);
});

test('a rewritten chain under an honest checkpoint is detected', function (): void {
    auditEntries(3);
    resolve(CreateAuditCheckpoint::class)->handle();
    $ids = auditIds();

    // Delete the first entry and rebuild every later hash: a consistent chain, but shorter.
    withoutAuditTriggers(function () use ($ids): void {
        DB::table('audit_events')->where('id', $ids[0])->delete();
        $prev = AuditChain::GENESIS;

        foreach (AuditEvent::query()->orderBy('id')->get() as $event) {
            $hash = AuditChain::hash($prev, AuditChain::fields($event));
            DB::table('audit_events')->where('id', $event->id)->update(['prev_hash' => $prev, 'hash' => $hash]);
            $prev = $hash;
        }
    });

    $result = resolve(VerifyAuditChain::class)->handle();

    expect($result->valid)->toBeFalse()
        ->and($result->problem)->toBe(AuditChainProblem::CheckpointMismatch);
});

test('the command records the result for the UI and prints the public key', function (): void {
    auditEntries(2);
    $this->artisan('launchroom:audit:checkpoint')->assertSuccessful();
    $this->artisan('launchroom:audit:verify')->expectsOutputToContain('verified up to')->assertSuccessful();

    $status = InstanceSettings::current()->audit_verification;
    expect($status['ok'] ?? null)->toBeTrue()
        ->and($status['verifiedUpTo'] ?? null)->toBeString();

    $this->artisan('launchroom:audit:verify --public-key')
        ->expectsOutputToContain(TestCase::SIGNING_KID.' ed25519:')
        ->assertSuccessful();

    $ids = auditIds();
    withoutAuditTriggers(fn () => DB::table('audit_events')->where('id', $ids[0])->update(['action' => 'team.member_removed']));
    $this->artisan('launchroom:audit:verify')->assertFailed();

    $status = InstanceSettings::current()->audit_verification;
    expect($status['ok'] ?? null)->toBeFalse()
        ->and($status['problem']['kind'] ?? null)->toBe('edited')
        ->and($status['problem']['eventId'] ?? null)->toBe($ids[0]);
});
