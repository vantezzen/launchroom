<?php

declare(strict_types=1);

use App\Actions\Security\EvaluateSecurityFindings;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertStatus;
use App\Enums\AuditAction;
use App\Enums\EnvironmentKind;
use App\Enums\Security\SecurityFindingStatus;
use App\Enums\TeamRole;
use App\Models\Alerts\Alert;
use App\Models\AuditEvent;
use App\Models\Security\SecurityFinding;
use App\Models\Team;
use App\Queries\Security\FixGroups;
use App\Support\Security\FixGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\Alerts;
use Tests\Support\Projects;
use Tests\Support\Security;

/*
| "Action needed" grouped by what to fix (D107, docs/22 §5.6, §9.2, §10):
| one group per package however many advisories and services it has, one
| alert per package, "Not affected…" for a whole group with exceptions, a
| new affected service bringing the group back, and security alerts of one
| scan folded into one Activity row.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Security::fakeDns();
    Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
    CarbonImmutable::setTestNow('2026-10-20 12:00:30');
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    Alerts::channel($this->team, $this->owner);
    Security::server($this->team);

    $this->shop = Projects::create($this->team, 'Shop');
    $this->blog = Projects::create($this->team, 'Blog');
    [, $this->api, $apiDeployment] = Security::liveService($this->shop, 'api', image: '127.0.0.1:5000/apps/api@sha256:'.str_repeat('a', 64));
    [, $this->site, $siteDeployment] = Security::liveService($this->blog, 'site', image: '127.0.0.1:5000/apps/site@sha256:'.str_repeat('b', 64));
    $this->blog->production()->sole()->forceFill(['kind' => EnvironmentKind::Persistent, 'name' => 'staging'])->save();

    Security::inventory($apiDeployment, [Security::component('npm', 'lodash', '4.17.20'), Security::component('npm', 'next', '14.1.0')]);
    Security::inventory($siteDeployment, [Security::component('npm', 'lodash', '4.17.20')]);
    Security::advisory('GHSA-35jh-r3h4-6jhm', ['summary' => 'Command injection in lodash', 'cwe_ids' => ['CWE-94']]);
    Security::advisory('GHSA-29mw-wpgm-hmr9', ['summary' => 'ReDoS in lodash', 'severity_label' => 'HIGH', 'cwe_ids' => ['CWE-1333'], 'affected' => [['ecosystem' => 'npm', 'name' => 'lodash', 'fixed' => ['4.17.21']]]]);
    Security::advisory('GHSA-f82v-jwr5-mffw', ['summary' => 'Authorization bypass in Next.js middleware', 'cwe_ids' => ['CWE-285'], 'affected' => [['ecosystem' => 'npm', 'name' => 'next', 'fixed' => ['14.2.25']]]]);
    Security::match('npm', 'lodash', '4.17.20', ['GHSA-35jh-r3h4-6jhm', 'GHSA-29mw-wpgm-hmr9']);
    Security::match('npm', 'next', '14.1.0', ['GHSA-f82v-jwr5-mffw']);

    $this->evaluate = fn (): array => resolve(EvaluateSecurityFindings::class)->handle($this->team);
    ($this->evaluate)();
    $this->lodash = FixGroup::key('npm', 'lodash');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function firingVulnerabilityAlerts(): array
{
    return Alert::query()->where('rule', AlertRule::Vulnerability->value)->where('status', AlertStatus::Firing->value)->orderBy('id')->get()->all();
}

test('the page shows one group per package with its services and advisories', function (): void {
    $data = $this->actingAs($this->viewer)->getJson('/security/-/data')->assertOk();

    expect(SecurityFinding::query()->count())->toBe(5)
        ->and(array_column($data->json('fixGroups'), 'package'))->toBe(['lodash', 'next'])
        ->and($data->json('summary.actionNeeded'))->toBe(2)
        ->and($data->json('summary.actionNeededServices'))->toBe(2)
        ->and(FixGroups::count($this->team->id))->toBe(2);

    $lodash = $data->json('fixGroups.0');
    expect($lodash['key'])->toBe($this->lodash)
        ->and($lodash['fixedVersion'])->toBe('4.17.21')
        ->and($lodash['versions'])->toBe(['4.17.20'])
        ->and($lodash['production'])->toBeTrue()
        ->and($lodash['impact'])->toBe('Attackers could run code on the server or crash or slow down the service.')
        ->and(array_column($lodash['advisories'], 'id'))->toBe(['GHSA-35jh-r3h4-6jhm', 'GHSA-29mw-wpgm-hmr9'])
        ->and(array_map(static fn (array $s): string => $s['service']['name'], $lodash['services']))->toBe(['api', 'site'])
        ->and(array_column($lodash['services'], 'production'))->toBe([true, false])
        ->and($lodash['services'][0]['findings'])->toHaveCount(2)
        ->and($data->json('fixGroups.1.impact'))->toBe('Attackers could get past authorization.');
});

test('one alert per package names every service', function (): void {
    $alerts = firingVulnerabilityAlerts();

    expect($alerts)->toHaveCount(2)
        ->and(array_map(static fn (Alert $a): string => $a->subject_key, $alerts))->toEqualCanonicalizing(['security:'.$this->lodash, 'security:'.FixGroup::key('npm', 'next')]);

    $lodash = collect($alerts)->firstWhere('subject_key', 'security:'.$this->lodash);
    expect($lodash?->title)->toBe('lodash 4.17.20 in 2 services has 2 vulnerabilities, including a critical one')
        ->and($lodash?->summary)->toStartWith('Fixed in 4.17.21. Affects api in Shop and site in Blog (staging).')
        ->and($lodash?->facts)->toMatchArray(['Advisories' => '2', 'Services' => '2', 'Package' => 'lodash 4.17.20'])
        ->and($lodash?->service_instance_id)->toBeNull()
        ->and($lodash?->href)->toBe('/security#'.$this->lodash);

    // Evaluating again changes nothing and notifies nobody.
    ($this->evaluate)();
    expect(Alert::query()->where('rule', AlertRule::Vulnerability->value)->count())->toBe(2);
});

test('"Not affected" for a group dismisses the chosen services and keeps the exceptions', function (): void {
    $api = SecurityFinding::query()->where('service_instance_id', $this->api->id)->where('name', 'lodash')->pluck('public_id')->all();

    $this->actingAs($this->developer)->postJson('/security/findings/dismiss', ['findings' => $api, 'reason' => 'not_used', 'note' => "We never\x07 call template()"])
        ->assertOk()->assertJson(['status' => 'dismissed', 'count' => 2]);

    $dismissed = SecurityFinding::query()->where('status', SecurityFindingStatus::Dismissed->value)->get();
    expect($dismissed)->toHaveCount(2)
        ->and($dismissed->pluck('dismiss_note')->unique()->all())->toBe(['We never call template()'])
        ->and(SecurityFinding::query()->where('service_instance_id', $this->site->id)->where('status', SecurityFindingStatus::Open->value)->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', AuditAction::SecurityFindingDismissed->value)->count())->toBe(1);

    // The exception keeps the group and its alert.
    $data = $this->actingAs($this->viewer)->getJson('/security/-/data')->assertOk();
    expect(array_map(static fn (array $s): string => $s['service']['name'], $data->json('fixGroups.1.services')))->toBe(['site'])
        ->and(collect(firingVulnerabilityAlerts())->pluck('subject_key')->all())->toContain('security:'.$this->lodash);

    $site = SecurityFinding::query()->where('service_instance_id', $this->site->id)->pluck('public_id')->all();
    $this->actingAs($this->developer)->postJson('/security/findings/dismiss', ['findings' => $site, 'reason' => 'mitigated'])->assertOk();

    expect(collect(firingVulnerabilityAlerts())->pluck('subject_key')->all())->toBe(['security:'.FixGroup::key('npm', 'next')]);
});

test('a new service with the package brings a dismissed group back with only that service', function (): void {
    $lodash = SecurityFinding::query()->where('name', 'lodash')->pluck('public_id')->all();
    $this->actingAs($this->developer)->postJson('/security/findings/dismiss', ['findings' => $lodash, 'reason' => 'accepted'])->assertOk();
    expect(array_column($this->actingAs($this->viewer)->getJson('/security/-/data')->json('fixGroups'), 'package'))->toBe(['next']);

    [, , $deployment] = Security::liveService($this->shop, 'worker', image: '127.0.0.1:5000/apps/worker@sha256:'.str_repeat('c', 64));
    Security::inventory($deployment, [Security::component('npm', 'lodash', '4.17.20')]);
    ($this->evaluate)();

    $data = $this->actingAs($this->viewer)->getJson('/security/-/data')->assertOk();
    $group = collect($data->json('fixGroups'))->firstWhere('package', 'lodash');
    expect(array_map(static fn (array $s): string => $s['service']['name'], $group['services'] ?? []))->toBe(['worker'])
        ->and(Alert::query()->where('subject_key', 'security:'.$this->lodash)->where('status', AlertStatus::Firing->value)->value('title'))
        ->toBe('worker uses lodash 4.17.20, which has 2 vulnerabilities, including a critical one');
});

test('dismissing a group needs projects.manage and only touches the team\'s open findings', function (): void {
    $ids = SecurityFinding::query()->pluck('public_id')->all();

    $this->actingAs($this->viewer)->postJson('/security/findings/dismiss', ['findings' => $ids, 'reason' => 'not_used'])->assertForbidden();

    $other = Team::factory()->create();
    $stranger = memberOf($other, TeamRole::Owner);
    $this->actingAs($stranger)->postJson('/security/findings/dismiss', ['findings' => $ids, 'reason' => 'not_used'])->assertConflict();

    $this->actingAs($this->developer)->postJson('/security/findings/dismiss', ['findings' => ['not-an-id'], 'reason' => 'not_used'])->assertUnprocessable();
    $this->actingAs($this->developer)->postJson('/security/findings/dismiss', ['findings' => [], 'reason' => 'not_used'])->assertUnprocessable();

    expect(SecurityFinding::query()->where('status', SecurityFindingStatus::Open->value)->count())->toBe(5);
});

test('the finding page steps through things to fix, not advisories', function (): void {
    $finding = SecurityFinding::query()->where('service_instance_id', $this->site->id)->where('group_key', 'GHSA-29mw-wpgm-hmr9')->sole();

    $this->actingAs($this->viewer)->get("/security/findings/{$finding->public_id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('siblings.position', 1)->where('siblings.total', 2)
            ->where('siblings.previous', null)
            ->where('siblings.next', fn (?string $next): bool => is_string($next) && str_starts_with($next, '/security/findings/fnd_')));
});

test('security alerts of one scan are one Activity row', function (): void {
    $this->actingAs($this->viewer)->get(route('activity.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('entries', function ($entries): bool {
            $alerts = collect($entries)->where('kind', 'alert')->values();

            return $alerts->count() === 1
                && in_array($alerts[0]['title'], ['Security updates needed for lodash and next', 'Security updates needed for next and lodash'], true)
                && $alerts[0]['href'] === '/security'
                && $alerts[0]['status'] === 'firing'
                && str_starts_with((string) $alerts[0]['detail'], 'Most urgent: ');
        }));
});
