<?php

declare(strict_types=1);

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Audit\VerifyAuditChain;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Support\AuditChain;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function recordSome(int $count): void
{
    $record = resolve(RecordAuditEvent::class);

    for ($i = 0; $i < $count; $i++) {
        $record->handle(new AuditEntryData(AuditAction::MemberInvited, AuditActor::system(), meta: ['email' => "u{$i}@example.com", 'role' => 'viewer']));
    }
}

test('entries form a hash chain starting at the genesis hash', function (): void {
    recordSome(3);
    $events = AuditEvent::query()->orderBy('id')->get();

    expect($events[0]->prev_hash)->toBe(AuditChain::GENESIS)
        ->and($events[1]->prev_hash)->toBe($events[0]->hash)
        ->and($events[2]->prev_hash)->toBe($events[1]->hash)
        ->and(resolve(VerifyAuditChain::class)->handle()->valid)->toBeTrue();

    $this->artisan('launchroom:audit:verify')->expectsOutputToContain('intact (3 entries')->assertSuccessful();
});

test('the database refuses updates and deletes', function (): void {
    recordSome(1);

    expect(fn () => DB::table('audit_events')->update(['action' => 'x']))->toThrow(QueryException::class);
});

test('tampering with an entry is detected', function (): void {
    recordSome(3);
    $middle = AuditEvent::query()->orderBy('id')->skip(1)->firstOrFail();

    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update_delete');
    DB::table('audit_events')->where('id', $middle->id)->update(['meta' => json_encode(['email' => 'evil@example.com', 'role' => 'owner'])]);
    DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_update_delete');

    $result = resolve(VerifyAuditChain::class)->handle();

    expect($result->valid)->toBeFalse()
        ->and($result->firstInvalidId)->toBe($middle->id)
        ->and($result->checked)->toBe(1);
    $this->artisan('launchroom:audit:verify')->assertFailed();
});

test('deleting an entry breaks the chain', function (): void {
    recordSome(3);
    $first = AuditEvent::query()->orderBy('id')->firstOrFail();

    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_no_update_delete');
    DB::table('audit_events')->where('id', $first->id)->delete();
    DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_no_update_delete');

    expect(resolve(VerifyAuditChain::class)->handle()->valid)->toBeFalse();
});
