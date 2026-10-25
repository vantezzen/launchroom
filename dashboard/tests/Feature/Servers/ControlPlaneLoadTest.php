<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use App\Queries\ControlPlaneLoad;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Per-minute host samples of the control plane for the last `$minutes`.
 *
 * @param  array<string, float|int>  $values
 */
$samples = static function (Server $server, int $minutes, array $values): void {
    for ($i = 0; $i < $minutes; $i++) {
        DB::table('server_metric_samples')->insert([
            'server_id' => $server->id, 'resolution' => 1,
            'at' => CarbonImmutable::now()->startOfMinute()->subMinutes($i)->toIso8601String(),
            'cpu_pct' => 20.0, 'mem_used_mb' => 2000, 'mem_total_mb' => 8000, ...$values,
        ]);
    }
};

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-25 12:00:30');
    Cache::flush();
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->server = Server::factory()->online()->create(['team_id' => $this->team->id, 'is_control_plane' => true, 'name' => 'main']);
});

test('busy CPU for several minutes raises the flag, a short spike does not', function () use ($samples): void {
    $samples($this->server, 2, ['cpu_pct' => 99.0]);
    expect(resolve(ControlPlaneLoad::class)->compute(CarbonImmutable::now()))->toBeNull();

    DB::table('server_metric_samples')->delete();
    $samples($this->server, 5, ['cpu_pct' => 95.0]);
    expect(resolve(ControlPlaneLoad::class)->compute(CarbonImmutable::now()))
        ->toBe(['server' => $this->server->id, 'cpu' => 95.0, 'memory' => 25.0]);
});

test('memory pressure raises the flag; normal load and other servers do not', function () use ($samples): void {
    $other = Server::factory()->online()->create(['team_id' => $this->team->id]);
    $samples($other, 5, ['cpu_pct' => 100.0]);
    $samples($this->server, 5, ['cpu_pct' => 40.0]);
    expect(resolve(ControlPlaneLoad::class)->compute(CarbonImmutable::now()))->toBeNull();

    DB::table('server_metric_samples')->where('server_id', $this->server->id)->update(['mem_used_mb' => 7800]);
    expect(resolve(ControlPlaneLoad::class)->compute(CarbonImmutable::now()))
        ->toBe(['server' => $this->server->id, 'cpu' => 40.0, 'memory' => 97.5]);
});

test('old samples do not count', function () use ($samples): void {
    $samples($this->server, 5, ['cpu_pct' => 95.0]);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(10));

    expect(resolve(ControlPlaneLoad::class)->compute(CarbonImmutable::now()))->toBeNull();
});

test('the flag is cached briefly instead of queried on every page', function () use ($samples): void {
    $load = resolve(ControlPlaneLoad::class);
    expect($load->current())->toBeNull();

    $samples($this->server, 5, ['cpu_pct' => 95.0]);
    expect($load->current())->toBeNull();

    $this->travel(61)->seconds();
    expect($load->current())->not->toBeNull();
});

test('the sidebar notice goes to people who manage the server only', function () use ($samples): void {
    $samples($this->server, 5, ['cpu_pct' => 95.0]);
    $admin = memberOf($this->team, TeamRole::Admin);
    $developer = memberOf($this->team, TeamRole::Developer);
    $stranger = memberOf(Team::factory()->create(), TeamRole::Owner);

    foreach ([$this->owner, $admin] as $user) {
        $this->actingAs($user)->get('/servers')->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('controlPlaneLoad.serverId', $this->server->public_id)
            ->where('controlPlaneLoad.serverName', 'main')
            ->where('controlPlaneLoad.cpuPct', 95));
    }

    foreach ([$developer, $stranger] as $user) {
        $this->actingAs($user)->get('/servers')->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('controlPlaneLoad', null));
    }
});
