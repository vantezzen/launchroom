<?php

declare(strict_types=1);

use App\Actions\Security\EvaluateSecurityFindings;
use App\Enums\TeamRole;
use App\Models\Security\SecurityFinding;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Projects;
use Tests\Support\Security;

/*
| The Security page narrowed to a project or a service (docs/22 §9.2):
| `?service=` and `?project=` scope every section, ids outside the team
| are a 404 on the data and ignored on the page; a finding names the
| deployment it was found in.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Security::fakeDns();
    Http::fake(['*' => Http::response(['results' => []])]);
    CarbonImmutable::setTestNow('2026-10-17 12:10:00');
    $this->team = Team::factory()->create();
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    $this->server = Security::server($this->team);
    $this->shop = Projects::create($this->team, 'Shop');
    [$this->api, $this->apiInstance, $this->deployment] = Security::liveService($this->shop, 'api', image: '127.0.0.1:5000/apps/x@'.Security::DIGEST);
    Security::inventory($this->deployment, [Security::component('npm', 'lodash', '4.17.20')]);
    $this->blog = Projects::create($this->team, 'Blog');
    [$this->site, $this->siteInstance] = Security::liveService($this->blog, 'site');
    Security::advisory('GHSA-35jh-r3h4-6jhm', ['aliases' => ['CVE-2021-23337'], 'cve_ids' => ['CVE-2021-23337']]);
    Security::match('npm', 'lodash', '4.17.20', ['GHSA-35jh-r3h4-6jhm']);
    resolve(EvaluateSecurityFindings::class)->handle($this->team);

    foreach ([[$this->apiInstance, $this->shop, 5], [$this->siteInstance, $this->blog, 3]] as [$instance, $project, $blocked]) {
        DB::table('waf_samples')->insert([
            'server_id' => $this->server->id, 'route_id' => 'rt_01J9Z8X7W6V5T4S3R2Q1P0N9N'.$blocked, 'resolution' => 10, 'at' => '2026-10-17T11:00:00Z',
            'service_instance_id' => $instance->id, 'project_id' => $project->id, 'blocked' => $blocked, 'detected' => 0,
            'categories' => json_encode([['c' => 'sqli', 'b' => $blocked, 'd' => 0]]), 'rules' => '[]', 'paths' => '[]',
        ]);
        DB::table('waf_events')->insert([
            'server_id' => $this->server->id, 'route_id' => 'rt_01J9Z8X7W6V5T4S3R2Q1P0N9N'.$blocked, 'service_instance_id' => $instance->id, 'project_id' => $project->id,
            'at' => '2026-10-17T11:03:12Z', 'action' => 'blocked', 'method' => 'GET', 'path' => '/p'.$blocked, 'rule_ids' => '[942100]', 'categories' => '["sqli"]',
            'targets' => '[]', 'client_network' => '203.0.113.0/24', 'score' => 5, 'reference' => 'ref'.$blocked,
        ]);
    }
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('the whole team is the default scope', function (): void {
    $data = $this->actingAs($this->viewer)->getJson('/security/-/data?range=24h')->assertOk();

    expect($data->json('scope'))->toBeNull()
        ->and($data->json('summary.blocked'))->toBe(8)
        ->and($data->json('firewall.recent'))->toHaveCount(2)
        ->and($data->json('actionNeeded'))->toHaveCount(1);
});

test('a service scope narrows every section to that service', function (): void {
    $data = $this->actingAs($this->viewer)->getJson("/security/-/data?range=24h&service={$this->site->public_id}")->assertOk();

    expect($data->json('scope'))->toMatchArray(['kind' => 'service', 'id' => $this->site->public_id, 'name' => 'site', 'projectName' => 'Blog'])
        ->and($data->json('summary.blocked'))->toBe(3)
        ->and(array_column($data->json('firewall.recent'), 'path'))->toBe(['/p3'])
        ->and($data->json('firewall.services.0.service.name'))->toBe('site')
        ->and($data->json('actionNeeded'))->toBe([]);
});

test('a project scope narrows to its services', function (): void {
    $data = $this->actingAs($this->viewer)->getJson("/security/-/data?range=24h&project={$this->shop->public_id}")->assertOk();

    expect($data->json('scope'))->toMatchArray(['kind' => 'project', 'name' => 'Shop'])
        ->and($data->json('summary.blocked'))->toBe(5)
        ->and(array_column($data->json('firewall.recent'), 'path'))->toBe(['/p5'])
        ->and($data->json('actionNeeded.0.package'))->toBe('lodash');
});

test('another team\'s ids are a 404 on the data and ignored on the page', function (): void {
    $foreign = Projects::create(Team::factory()->create(), 'Foreign');
    $foreignService = Projects::image($foreign, 'web');

    $this->actingAs($this->viewer)->getJson("/security/-/data?service={$foreignService->public_id}")->assertNotFound();
    $this->actingAs($this->viewer)->getJson("/security/-/data?project={$foreign->public_id}")->assertNotFound();
    $this->actingAs($this->viewer)->getJson('/security/-/data?service=not-an-id')->assertNotFound();
    $this->actingAs($this->viewer)->get("/security?service={$foreignService->public_id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('security')->where('service', null)->where('project', null));
    $this->actingAs($this->viewer)->get("/security?project={$this->shop->public_id}")
        ->assertInertia(fn ($page) => $page->where('project', $this->shop->public_id));
});

test('a finding names the deployment it was found in', function (): void {
    $finding = SecurityFinding::query()->sole();
    $environment = $this->shop->production()->sole();

    $this->actingAs($this->viewer)->getJson("/security/findings/{$finding->public_id}/-/data")
        ->assertOk()
        ->assertJsonPath('deployment.id', $this->deployment->public_id)
        ->assertJsonPath('deployment.href', "/p/{$this->shop->public_id}/{$environment->public_id}/d/{$this->deployment->public_id}");
});
