<?php

declare(strict_types=1);

use App\Actions\Alerts\EvaluateAlertConditions;
use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Security\AddWafExclusion;
use App\Actions\Security\RemoveWafExclusion;
use App\Actions\Security\RollupWafSamples;
use App\Actions\Security\SetWafSettings;
use App\Actions\Servers\RecordReport;
use App\Actions\Servers\RenderNodeState;
use App\Data\Audit\AuditActor;
use App\Data\Protocol\V1\Report;
use App\Data\Security\AddWafExclusionData;
use App\Data\Security\SetWafSettingsData;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertStatus;
use App\Enums\AuditAction;
use App\Enums\DaemonFeature;
use App\Enums\Security\WafMode;
use App\Enums\TeamRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\Alerts\Alert;
use App\Models\AuditEvent;
use App\Models\Domain;
use App\Models\Placement;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;
use Tests\Support\Security;

/*
| The web firewall on the dashboard side (docs/22 §6, docs/03 §13.3–13.4):
| RouteSpec.waf only for edges with the `waf` feature, defaults for new and
| existing instances, exclusions as typed fields, report ingestion of
| Report.ingress.waf and Report.metrics.waf (idempotent, attributed, client
| networks only), rollups and retention, the waf_unavailable alert.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    CarbonImmutable::setTestNow('2026-10-17 12:10:05');
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->server = Security::server($this->team);
    $this->project = Projects::create($this->team, 'shop');
    $this->service = Projects::image($this->project, 'web');
    resolve(DeployEnvironment::class)->handle($this->project->production()->sole(), null, AuditActor::system());
    Projects::report($this->server);
    Projects::report($this->server);
    $this->instance = ServiceInstance::query()->where('service_id', $this->service->id)->sole();
    $this->actor = AuditActor::user($this->owner);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @return list<array<string, mixed>>
 */
function wafRoutes(Server $server): array
{
    $document = resolve(RenderNodeState::class)->handle($server->refresh())->version->document;
    expect(ProtocolSchemas::errors('node-state', $document))->toBe([]);

    return json_decode($document, true, 64, JSON_THROW_ON_ERROR)['routes'];
}

test('new service instances block, and every route of the instance carries the setting', function (): void {
    $routes = wafRoutes($this->server);

    expect($this->instance->waf_mode)->toBe(WafMode::Block)
        ->and($routes)->toHaveCount(2)
        ->and(array_column($routes, 'waf'))->toBe([['mode' => 'block'], ['mode' => 'block']]);
});

test('mode, paranoia, body limit and exclusions render as typed fields, Off renders nothing', function (): void {
    resolve(SetWafSettings::class)->handle($this->instance, SetWafSettingsData::from(['mode' => 'log', 'paranoia' => 2, 'inspectBodyKiB' => 128]), $this->actor);
    resolve(AddWafExclusion::class)->handle($this->instance, new AddWafExclusionData(942100, '/api/search', 'ARGS:q', 'Search terms'), $this->owner, $this->actor);
    $second = resolve(AddWafExclusion::class)->handle($this->instance, new AddWafExclusionData(920350), $this->owner, $this->actor);

    expect(wafRoutes($this->server)[0]['waf'])->toBe([
        'mode' => 'log',
        'paranoia' => 2,
        'inspectBodyKiB' => 128,
        'exclusions' => [['rule' => 942100, 'path' => '/api/search', 'target' => 'ARGS:q'], ['rule' => 920350]],
    ]);

    resolve(RemoveWafExclusion::class)->handle($second, $this->actor);
    resolve(SetWafSettings::class)->handle($this->instance, SetWafSettingsData::from(['mode' => 'off']), $this->actor);

    expect(array_column(wafRoutes($this->server), 'waf'))->toBe([])
        ->and(AuditEvent::query()->pluck('action')->map->value->all())->toContain(
            AuditAction::WafModeChanged->value,
            AuditAction::WafParanoiaChanged->value,
            AuditAction::WafInspectBodyChanged->value,
            AuditAction::WafExclusionAdded->value,
            AuditAction::WafExclusionRemoved->value,
        );
});

test('exclusions are refused when duplicate, beyond 50 or outside the detection rules', function (): void {
    $add = resolve(AddWafExclusion::class);
    $add->handle($this->instance, new AddWafExclusionData(942100, '/api'), $this->owner, $this->actor);

    expect(fn () => $add->handle($this->instance, new AddWafExclusionData(942100, '/api'), $this->owner, $this->actor))
        ->toThrow(DomainRuleViolation::class, 'This exception already exists.');

    expect(fn (): AddWafExclusionData => AddWafExclusionData::validateAndCreate(['rule' => 949110]))->toThrow(ValidationException::class)
        ->and(fn (): AddWafExclusionData => AddWafExclusionData::validateAndCreate(['rule' => 942100, 'path' => '/api/{x}']))->toThrow(ValidationException::class)
        ->and(fn (): AddWafExclusionData => AddWafExclusionData::validateAndCreate(['rule' => 942100, 'target' => 'ARGS:q;Include x']))->toThrow(ValidationException::class);

    for ($rule = 941100; $rule < 941149; $rule++) {
        $add->handle($this->instance, new AddWafExclusionData($rule), $this->owner, $this->actor);
    }

    expect(fn () => $add->handle($this->instance, new AddWafExclusionData(941150), $this->owner, $this->actor))
        ->toThrow(DomainRuleViolation::class, 'at most 50');
});

test('edges without the waf feature get no firewall settings', function (): void {
    $this->server->forceFill(['daemon_features' => [DaemonFeature::Inventory->value]])->save();

    expect(array_column(wafRoutes($this->server), 'waf'))->toBe([]);
});

test('the migration starts existing and new instances in Block', function (): void {
    $migration = require database_path('migrations/2026_12_12_100000_create_security_center.php');
    $migration->down();
    $migration->up();

    expect(DB::table('service_instances')->where('id', $this->instance->id)->value('waf_mode'))->toBe('block');

    $newer = Projects::image($this->project, 'api');
    expect(ServiceInstance::query()->where('service_id', $newer->id)->sole()->refresh()->waf_mode)->toBe(WafMode::Block);
});

test('instances that started in Log only move to Block unless someone chose it', function (): void {
    $chosen = ServiceInstance::query()->where('service_id', Projects::image($this->project, 'api')->id)->sole();
    DB::table('service_instances')->where('id', $this->instance->id)->update(['waf_mode' => 'log', 'waf_mode_changed_at' => null]);
    DB::table('service_instances')->where('id', $chosen->id)->update(['waf_mode' => 'log', 'waf_mode_changed_at' => now()]);

    (require database_path('migrations/2026_12_19_110000_block_attacks_on_existing_instances.php'))->up();

    expect($this->instance->refresh()->waf_mode)->toBe(WafMode::Block)
        ->and($chosen->refresh()->waf_mode)->toBe(WafMode::Log);
});

/**
 * The recorded report fixture with its route ids pointed at our domain and deployment.
 *
 * @return array<string, mixed>
 */
function wafReport(object $test, int $seq = 1): array
{
    $report = json_decode((string) file_get_contents(ProtocolSchemas::root().'/fixtures/v1/report.waf.json'), true);
    $report['serverId'] = $test->server->public_id;
    $report['seq'] = $seq;
    $report['metrics']['waf'][0]['route'] = $test->domainRoute;
    $report['metrics']['waf'][1]['route'] = $test->deploymentRoute;

    return $report;
}

test('firewall samples and events are stored idempotently, attributed by route, with the client network only', function (): void {
    $domain = Domain::query()->where('service_instance_id', $this->instance->id)->firstOrFail();
    $this->domainRoute = Domain::routeIdFor($domain->public_id);
    $this->deploymentRoute = 'rt_'.substr((string) $this->instance->activeDeployment?->public_id, 4);
    $body = wafReport($this);

    expect(ProtocolSchemas::errors('report', (string) json_encode($body)))->toBe([]);

    $record = fn (array $report) => resolve(RecordReport::class)->handle($this->server, Report::validateAndCreate($report));
    $record($body);
    $record(wafReport($this, 2));

    expect(DB::table('waf_samples')->count())->toBe(2)
        ->and(DB::table('waf_samples')->where('route_id', $this->domainRoute)->value('blocked'))->toBe(14)
        ->and(DB::table('waf_samples')->distinct()->pluck('service_instance_id')->all())->toBe([$this->instance->id])
        ->and(DB::table('waf_events')->count())->toBe(3)
        ->and(DB::table('waf_events')->orderBy('at')->pluck('client_network')->all())->toBe(['203.0.113.0/24', '2001:db8::/48', '198.51.100.0/24']);

    $status = $this->server->refresh()->waf_status;
    expect($status)->toMatchArray(['state' => 'on', 'engine' => 'coraza 3.8.1', 'ruleSet' => 'OWASP CRS 4.25.0', 'routes' => 2]);

    // A full address or a non-canonical network never gets stored.
    $bad = wafReport($this, 3);
    $bad['metrics']['waf'][0]['at'] = '2026-10-17T11:50:00Z';
    $bad['metrics']['waf'][0]['events'][0]['at'] = '2026-10-17T11:53:12Z';
    $bad['metrics']['waf'][0]['events'][1]['at'] = '2026-10-17T11:55:40Z';
    $bad['metrics']['waf'][0]['events'][0]['clientNetwork'] = '203.0.113.7/24';
    $bad['metrics']['waf'][0]['events'][0]['reference'] = 'other-ref';
    $bad['metrics']['waf'][0]['events'][1]['clientNetwork'] = '203.0.113.9';
    unset($bad['metrics']['waf'][1]);
    $record($bad);

    expect(DB::table('waf_events')->where('client_network', 'like', '203.0.113.%')->where('client_network', '!=', '203.0.113.0/24')->count())->toBe(0)
        ->and(DB::table('waf_samples')->count())->toBe(3);
});

test('samples whose counts don\'t add up are dropped', function (): void {
    $domain = Domain::query()->where('service_instance_id', $this->instance->id)->firstOrFail();
    $this->domainRoute = Domain::routeIdFor($domain->public_id);
    $this->deploymentRoute = 'rt_'.substr((string) $this->instance->activeDeployment?->public_id, 4);
    $body = wafReport($this);
    $body['metrics']['waf'][0]['otherPaths']['blocked'] = 5;

    resolve(RecordReport::class)->handle($this->server, Report::validateAndCreate($body));

    expect(DB::table('waf_samples')->count())->toBe(1);
});

test('rollups fold 10-minute rows into hours and retention removes old rows', function (): void {
    $insert = fn (string $at, int $blocked, string $resolution = '10') => DB::table('waf_samples')->insert([
        'server_id' => $this->server->id, 'route_id' => 'rt_01J9Z8X7W6V5T4S3R2Q1P0N9N9', 'resolution' => $resolution, 'at' => $at,
        'service_instance_id' => $this->instance->id, 'project_id' => $this->project->id, 'blocked' => $blocked, 'detected' => 0,
        'categories' => json_encode([['c' => 'sqli', 'b' => $blocked, 'd' => 0]]), 'rules' => json_encode([['r' => 942100, 'b' => $blocked, 'd' => 0]]),
        'paths' => json_encode([['p' => '/login', 'b' => $blocked, 'd' => 0]]),
    ]);
    $insert('2026-10-17T11:00:00Z', 3);
    $insert('2026-10-17T11:10:00Z', 4);
    $insert('2026-10-09T11:10:00Z', 1);
    $insert('2026-07-01T11:00:00Z', 1, '60');

    $result = resolve(RollupWafSamples::class)->handle();

    $hour = DB::table('waf_samples')->where('resolution', 60)->where('at', '2026-10-17 11:00:00+00')->first();
    expect($hour?->blocked)->toBe(7)
        ->and(json_decode((string) $hour?->categories, true))->toEqual([['c' => 'sqli', 'b' => 7, 'd' => 0]])
        ->and($result['pruned'])->toBe(2);
});

test('an edge serving routes without the firewall raises waf_unavailable after 5 minutes', function (): void {
    $report = fn (string $state) => resolve(RecordReport::class)->handle($this->server, Report::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'Report', 'serverId' => $this->server->public_id,
        'observedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'seq' => (int) (microtime(true) * 1_000_000), 'full' => true,
        'appliedVersion' => $this->server->refresh()->desired_version, 'workloads' => [],
        'ingress' => ['state' => 'applied', 'routes' => 2, 'waf' => $state === 'on'
            ? ['state' => 'on', 'engine' => 'coraza 3.8.1', 'ruleSet' => 'OWASP CRS 4.25.0', 'routes' => 2]
            : ['state' => 'error', 'engine' => 'coraza 3.8.1', 'ruleSet' => 'OWASP CRS 4.25.0', 'routes' => 0, 'message' => 'loading module waf: bad directive']],
    ]));

    $report('error');
    resolve(EvaluateAlertConditions::class)->handle();
    expect(Alert::query()->where('rule', AlertRule::WafUnavailable->value)->count())->toBe(0);

    CarbonImmutable::setTestNow('2026-10-17 12:16:05');
    $report('error');
    resolve(EvaluateAlertConditions::class)->handle();
    $alert = Alert::query()->where('rule', AlertRule::WafUnavailable->value)->sole();
    expect($alert->title)->toBe("The web firewall is off on {$this->server->name}")
        ->and($alert->summary)->toContain('loading module waf: bad directive');

    $report('on');
    resolve(EvaluateAlertConditions::class)->handle();
    expect($alert->refresh()->status)->toBe(AlertStatus::Resolved);
});

test('changing the firewall re-renders the team\'s edges', function (): void {
    $before = $this->server->refresh()->desired_version;

    resolve(SetWafSettings::class)->handle($this->instance, SetWafSettingsData::from(['mode' => 'log']), $this->actor);

    expect($this->server->refresh()->desired_version)->toBeGreaterThan($before)
        ->and(Placement::query()->count())->toBe(1);
});
