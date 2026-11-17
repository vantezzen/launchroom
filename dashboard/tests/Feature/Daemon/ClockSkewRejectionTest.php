<?php

declare(strict_types=1);

use App\Actions\Servers\RevokeServer;
use App\Data\Audit\AuditActor;
use App\Data\Servers\ServerNoticeData;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertStatus;
use App\Enums\Servers\RequestRejection;
use App\Enums\ServerStatus;
use App\Events\ServerStatusChanged;
use App\Listeners\Alerts\RaiseServerOfflineAlerts;
use App\Models\Alerts\Alert;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeDaemon;

beforeEach(function (): void {
    Cache::flush();
    $seed = random_bytes(32);
    $this->server = Server::factory()->withSigningSeed($seed)->online()->create();
    $this->daemon = new FakeDaemon($seed)->as($this->server->public_id);
});

test('a correctly signed request with a clock 90 s off is refused and the skew is recorded', function (): void {
    $this->daemon->send($this, 'GET', '/daemon/v1/state?have=0', created: now()->getTimestamp() - 90)->assertUnauthorized();

    $rejection = $this->server->refresh()->last_rejection;
    expect($rejection?->reason)->toBe(RequestRejection::ClockSkew)
        ->and($rejection?->skewSeconds)->toBe(-90)
        ->and($rejection?->explain()['title'])->toBe('Its clock is 90 seconds behind the dashboard');

    $notice = ServerNoticeData::forServer($this->server)[0];
    expect($notice->key)->toBe('rejected')
        ->and($notice->detail)->toContain('sudo timedatectl set-ntp true');
});

test('a bad signature never writes anything, even with a skewed clock', function (): void {
    new FakeDaemon()->as($this->server->public_id)->send($this, 'GET', '/daemon/v1/state?have=0', created: now()->getTimestamp() - 90)->assertUnauthorized();
    new FakeDaemon()->as($this->server->public_id)->send($this, 'GET', '/daemon/v1/state?have=0')->assertUnauthorized();

    expect(DB::table('servers')->where('id', $this->server->id)->value('last_rejection'))->toBeNull();
});

test('refusals are recorded at most once a minute', function (): void {
    $this->daemon->send($this, 'GET', '/daemon/v1/state?have=0', created: now()->getTimestamp() + 120)->assertUnauthorized();
    $this->daemon->send($this, 'GET', '/daemon/v1/state?have=0', created: now()->getTimestamp() - 300)->assertUnauthorized();

    expect($this->server->refresh()->last_rejection?->skewSeconds)->toBe(120);

    $this->travel(61)->seconds();
    $this->daemon->send($this, 'GET', '/daemon/v1/state?have=0', created: now()->getTimestamp() - 300)->assertUnauthorized();

    expect($this->server->refresh()->last_rejection?->skewSeconds)->toBe(-300);
});

test('a revoked server that still signs correctly is recorded as revoked', function (): void {
    resolve(RevokeServer::class)->handle($this->server, AuditActor::system());

    $this->daemon->send($this, 'GET', '/daemon/v1/state?have=0')->assertUnauthorized();

    expect($this->server->refresh()->last_rejection?->reason)->toBe(RequestRejection::Revoked);
});

test('an accepted request in time records nothing, and an older refusal stops counting after a report', function (): void {
    $this->daemon->send($this, 'GET', '/daemon/v1/state?have=0')->assertOk();
    expect($this->server->refresh()->last_rejection)->toBeNull();

    $this->daemon->send($this, 'GET', '/daemon/v1/state?have=0', created: now()->getTimestamp() - 90)->assertUnauthorized();
    $this->server->forceFill(['last_report_at' => now()->addSecond()])->save();

    expect(ServerNoticeData::forServer($this->server->refresh()))->toBe([]);
});

test('the offline alert names the refusal', function (): void {
    $this->daemon->send($this, 'GET', '/daemon/v1/state?have=0', created: now()->getTimestamp() - 90)->assertUnauthorized();
    $this->server->forceFill(['status' => ServerStatus::Offline])->save();

    resolve(RaiseServerOfflineAlerts::class)->handle(new ServerStatusChanged($this->server->id, ServerStatus::Online, ServerStatus::Offline));

    $alert = Alert::query()->where('rule', AlertRule::ServerOffline->value)->where('status', AlertStatus::Firing->value)->sole();
    expect($alert->summary)->toContain('Its clock is 90 seconds behind the dashboard')
        ->and($alert->summary)->not->toContain('lists what to check');
});
