<?php

declare(strict_types=1);

use App\Actions\Security\EvaluateSecurityFindings;
use App\Enums\ApiAbility;
use App\Enums\Security\SecurityFindingStatus;
use App\Enums\Security\WafMode;
use App\Enums\TeamRole;
use App\Models\Security\AdvisoryFeedState;
use App\Models\Security\SecurityFinding;
use App\Models\Security\WafExclusion;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Api;
use Tests\Support\Projects;
use Tests\Support\Security;

/*
| The Security page, findings, the service firewall and the advisory
| sources over HTTP (docs/22 §9, §11, §12): every member reads, developers
| dismiss and investigate, owners and admins see client networks and
| check now, instance owners switch sources. Other teams get 404. The API
| is read-only (ability `read`) and never returns networks.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Security::fakeDns();
    Http::fake(['*' => Http::response(['results' => []])]);
    CarbonImmutable::setTestNow('2026-10-17 12:10:00');
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->owner->forceFill(['is_instance_owner' => true])->save();
    $this->admin = memberOf($this->team, TeamRole::Admin);
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    $this->server = Security::server($this->team);
    $this->project = Projects::create($this->team, 'Shop');
    [$this->service, $this->instance, $this->deployment] = Security::liveService($this->project, 'api', image: '127.0.0.1:5000/apps/x@'.Security::DIGEST);
    Security::inventory($this->deployment, [Security::component('npm', 'lodash', '4.17.20'), Security::component('binary', 'node', '20.11.1')]);
    Security::advisory('GHSA-35jh-r3h4-6jhm', ['aliases' => ['CVE-2021-23337'], 'cve_ids' => ['CVE-2021-23337']]);
    Security::match('npm', 'lodash', '4.17.20', ['GHSA-35jh-r3h4-6jhm']);
    resolve(EvaluateSecurityFindings::class)->handle($this->team);
    $this->finding = SecurityFinding::query()->sole();
    DB::table('waf_events')->insert([
        'server_id' => $this->server->id, 'route_id' => 'rt_01J9Z8X7W6V5T4S3R2Q1P0N9N9', 'service_instance_id' => $this->instance->id, 'project_id' => $this->project->id,
        'at' => '2026-10-17T12:03:12Z', 'action' => 'blocked', 'method' => 'GET', 'path' => '/api/products/:id', 'rule_ids' => '[942100]', 'categories' => '["sqli"]',
        'targets' => '["ARGS:id"]', 'client_network' => '203.0.113.0/24', 'score' => 5, 'reference' => 'kJ3x9QpL2mN8vR4t',
    ]);
    $this->base = "/p/{$this->project->public_id}/{$this->project->production()->sole()->public_id}/s/{$this->service->public_id}";
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('every member sees the Security page and its data', function (User $user): void {
    $this->actingAs($user)->get('/security')->assertOk()->assertInertia(fn ($page) => $page->component('security'));

    $data = $this->actingAs($user)->getJson('/security/-/data?range=7d')->assertOk();
    expect($data->json('summary.actionNeeded'))->toBe(1)
        ->and($data->json('actionNeeded.0.package'))->toBe('lodash')
        ->and($data->json('actionNeeded.0.title'))->toBe('Command injection in lodash')
        ->and($data->json('firewall.recent.0.path'))->toBe('/api/products/:id')
        ->and($data->json('firewall.recent.0.rules.0.message'))->toBe('SQL injection attack detected via libinjection')
        ->and($data->json('runtimes.0.label'))->toBe('Node.js');
})->with([
    'owner' => fn () => $this->owner,
    'viewer' => fn () => $this->viewer,
]);

test('client networks are for owners and admins only', function (string $role, ?string $network): void {
    $user = $this->{$role};

    expect($this->actingAs($user)->getJson('/security/-/data')->json('firewall.recent.0.clientNetwork'))->toBe($network);
})->with([
    ['owner', '203.0.113.0/24'],
    ['admin', '203.0.113.0/24'],
    ['developer', null],
    ['viewer', null],
]);

test('a finding opens for members; dismissing and reopening need projects.manage', function (): void {
    $this->actingAs($this->viewer)->get("/security/findings/{$this->finding->public_id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('security/finding')->where('detail.finding.advisory', 'GHSA-35jh-r3h4-6jhm'));
    $this->actingAs($this->viewer)->postJson("/security/findings/{$this->finding->public_id}/dismiss", ['reason' => 'not_used'])->assertForbidden();

    $this->actingAs($this->developer)->postJson("/security/findings/{$this->finding->public_id}/dismiss", ['reason' => 'not_used', 'note' => 'Only in a CLI'])->assertOk();
    expect($this->finding->refresh()->status)->toBe(SecurityFindingStatus::Dismissed);

    $this->actingAs($this->developer)->postJson("/security/findings/{$this->finding->public_id}/reopen")->assertOk();
    expect($this->finding->refresh()->status)->toBe(SecurityFindingStatus::Open);
});

test('findings of other teams are not found', function (): void {
    $other = memberOf(Team::factory()->create(), TeamRole::Owner);

    $this->actingAs($other)->get("/security/findings/{$this->finding->public_id}")->assertNotFound();
    $this->actingAs($other)->postJson("/security/findings/{$this->finding->public_id}/dismiss", ['reason' => 'accepted'])->assertNotFound();
    $this->actingAs($other)->getJson("{$this->base}/firewall")->assertNotFound();
});

test('starting an investigation needs projects.manage', function (): void {
    $this->actingAs($this->viewer)->postJson("/security/findings/{$this->finding->public_id}/investigate")->assertForbidden();
    $this->actingAs($this->developer)->postJson("{$this->base}/investigate")->assertStatus(202);
});

test('the service firewall: members read, developers change it', function (): void {
    $firewall = $this->actingAs($this->viewer)->getJson("{$this->base}/firewall")->assertOk();
    expect($firewall->json('mode'))->toBe('block')->and($firewall->json('canManage'))->toBeFalse();

    $this->actingAs($this->viewer)->patchJson("{$this->base}/firewall", ['mode' => 'off'])->assertForbidden();
    $this->actingAs($this->developer)->patchJson("{$this->base}/firewall", ['mode' => 'log', 'paranoia' => 2, 'inspectBodyKiB' => 1024])->assertOk()->assertJson(['mode' => 'log', 'paranoia' => 2, 'inspectBodyKiB' => 1024]);
    $this->actingAs($this->developer)->patchJson("{$this->base}/firewall", ['inspectBodyKiB' => 64])->assertUnprocessable();
    expect($this->instance->refresh()->waf_mode)->toBe(WafMode::Log);

    $this->actingAs($this->developer)->postJson("{$this->base}/firewall/exclusions", ['rule' => 942100, 'path' => '/api/search', 'target' => 'ARGS:q'])->assertCreated();
    $this->actingAs($this->developer)->postJson("{$this->base}/firewall/exclusions", ['rule' => 980130])->assertUnprocessable();
    $exclusion = WafExclusion::query()->sole();

    expect($this->actingAs($this->viewer)->getJson("{$this->base}/firewall")->json('exclusions.0.message'))->toBe('SQL injection attack detected via libinjection');
    $this->actingAs($this->viewer)->deleteJson("{$this->base}/firewall/exclusions/{$exclusion->public_id}")->assertForbidden();
    $this->actingAs($this->developer)->deleteJson("{$this->base}/firewall/exclusions/{$exclusion->public_id}")->assertNoContent();
});

test('check now is for owners and admins, once per 10 minutes', function (): void {
    $this->actingAs($this->developer)->postJson('/security/check')->assertForbidden();
    $this->actingAs($this->admin)->postJson('/security/check')->assertStatus(202);
    $this->actingAs($this->owner)->postJson('/security/check')->assertConflict();
});

test('advisory sources are switched by the instance owner only', function (): void {
    $this->actingAs($this->admin)->put('/settings/instance-security/sources', ['osv' => false, 'kev' => true, 'eol' => true])->assertForbidden();
    $this->actingAs($this->owner)->put('/settings/instance-security/sources', ['osv' => false, 'kev' => true, 'eol' => true])->assertRedirect();

    expect(AdvisoryFeedState::query()->find('osv')?->enabled)->toBeFalse()
        ->and($this->actingAs($this->viewer)->getJson('/security/-/data')->json('checksOff'))->toBeTrue();
});

test('inventory search groups versions with their services and findings', function (): void {
    $result = $this->actingAs($this->viewer)->getJson('/security/inventory/-/search?q=lodash 4.17')->assertOk();

    expect($result->json('packages.0.name'))->toBe('lodash')
        ->and($result->json('packages.0.versions.0.version'))->toBe('4.17.20')
        ->and($result->json('packages.0.versions.0.services.0.name'))->toBe('api')
        ->and($result->json('packages.0.versions.0.findings.0.advisory'))->toBe('GHSA-35jh-r3h4-6jhm');

    expect($this->actingAs($this->viewer)->getJson('/security/inventory/-/search?q=lodash 5')->json('packages'))->toBe([]);
    $this->actingAs($this->viewer)->get('/security/inventory?q=node')->assertOk()->assertInertia(fn ($page) => $page->component('security')->where('inventoryQuery', 'node'));
});

test('the API lists findings, one finding, the inventory and firewall totals', function (): void {
    $token = Api::token($this->viewer, $this->team, [ApiAbility::Read]);

    $list = Api::get('security/findings?level=action_needed', $token)->assertOk();
    expect($list->json('data.0.advisory'))->toBe('GHSA-35jh-r3h4-6jhm')->and($list->json('data.0.level'))->toBe('action_needed');

    $one = Api::get("security/findings/{$this->finding->public_id}", $token)->assertOk();
    expect($one->json('data.checks.0.rule'))->toBe('A1');

    expect(Api::get('security/inventory?q=lodash', $token)->assertOk()->json('data.packages.0.name'))->toBe('lodash');

    $env = $this->project->production()->sole()->public_id;
    $inventory = Api::get("environments/{$env}/services/{$this->service->public_id}/inventory", $token)->assertOk();
    expect($inventory->json('data.components.0.name'))->toBe('node');

    expect(Api::get('security/waf?range=24h', $token)->assertOk()->json('data.range'))->toBe('24h');
});

test('the API never shows another team\'s finding', function (): void {
    $otherTeam = Team::factory()->create();
    $token = Api::token(memberOf($otherTeam, TeamRole::Owner), $otherTeam, [ApiAbility::Read]);

    Api::get("security/findings/{$this->finding->public_id}", $token)->assertNotFound();
    expect(Api::get('security/findings', $token)->json('data'))->toBe([]);
});

test('the service panel header carries the open Action needed finding', function (): void {
    expect($this->finding->level->value)->toBe('action_needed');

    $this->actingAs($this->viewer)->get("{$this->base}/deployments")->assertOk()
        ->assertInertia(fn ($page) => $page->where('panel.actionNeeded', 1)->where('panel.actionNeededFinding', $this->finding->public_id));

    $this->actingAs($this->developer)->postJson("/security/findings/{$this->finding->public_id}/dismiss", ['reason' => 'not_used'])->assertOk();

    $this->actingAs($this->viewer)->get("{$this->base}/deployments")->assertOk()
        ->assertInertia(fn ($page) => $page->where('panel.actionNeeded', 0)->where('panel.actionNeededFinding', null));
});
