<?php

declare(strict_types=1);

use App\Actions\Security\DismissFinding;
use App\Actions\Security\EvaluateSecurityFindings;
use App\Data\Audit\AuditActor;
use App\Data\Security\DismissFindingData;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertSeverity;
use App\Enums\Alerts\AlertStatus;
use App\Enums\EnvironmentKind;
use App\Enums\Security\DismissReason;
use App\Enums\Security\FindingLevel;
use App\Enums\Security\InventorySource;
use App\Enums\Security\InvestigationStatus;
use App\Enums\Security\SecurityFindingStatus;
use App\Enums\TeamRole;
use App\Models\Alerts\Alert;
use App\Models\Deployment;
use App\Models\Security\SecurityFinding;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Alerts;
use Tests\Support\Projects;
use Tests\Support\Security;

/*
| Findings lifecycle (docs/22 §5.5) and the `vulnerability` alert (§10):
| open, resolve on a new deployment, reopen on a rollback, dismiss and
| reopen automatically when it gets more urgent; one alert per service,
| repeated for a new advisory, resolved with the last urgent finding.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Security::fakeDns();
    Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
    CarbonImmutable::setTestNow('2026-10-20 12:00:30');
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    Alerts::channel($this->team, $this->owner);
    $this->server = Security::server($this->team);
    $this->project = Projects::create($this->team, 'Shop');
    [$this->service, $this->instance, $this->deployment] = Security::liveService($this->project, 'api', image: '127.0.0.1:5000/apps/x@'.Security::DIGEST);
    $this->inventory = Security::inventory($this->deployment, [Security::component('npm', 'lodash', '4.17.20')]);
    Security::advisory('GHSA-35jh-r3h4-6jhm', ['aliases' => ['CVE-2021-23337'], 'cve_ids' => ['CVE-2021-23337']]);
    Security::match('npm', 'lodash', '4.17.20', ['GHSA-35jh-r3h4-6jhm']);
    $this->evaluate = fn (): array => resolve(EvaluateSecurityFindings::class)->handle($this->team);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function vulnerabilityAlert(): ?Alert
{
    return Alert::query()->where('rule', AlertRule::Vulnerability->value)->where('status', AlertStatus::Firing->value)->first();
}

test('a matched advisory opens an "Action needed" finding, investigates it and alerts once', function (): void {
    $counts = ($this->evaluate)();
    $finding = SecurityFinding::query()->sole();

    expect($counts)->toBe(['open' => 1, 'actionNeeded' => 1, 'resolved' => 0])
        ->and($finding->level)->toBe(FindingLevel::ActionNeeded)
        ->and($finding->group_key)->toBe('GHSA-35jh-r3h4-6jhm')
        ->and($finding->fixed_version)->toBe('4.17.21')
        ->and($finding->public)->toBeTrue()
        ->and($finding->investigation?->status)->toBe(InvestigationStatus::Partial)
        ->and($finding->notified_at)->not->toBeNull();

    $alert = vulnerabilityAlert();
    expect($alert?->subject_key)->toBe('security:'.$this->instance->public_id)
        ->and($alert?->title)->toBe('api uses lodash 4.17.20, which has a critical vulnerability')
        ->and($alert?->severity)->toBe(AlertSeverity::Critical)
        ->and($alert?->facts)->toMatchArray(['Advisory' => 'GHSA-35jh-r3h4-6jhm', 'Package' => 'lodash 4.17.20', 'Fixed in' => '4.17.21'])
        ->and($alert?->summary)->toStartWith('Fixed in 4.17.21.')
        ->and($alert?->href)->toBe('/security/findings/'.$finding->public_id);

    // Evaluating again changes nothing.
    ($this->evaluate)();
    expect(SecurityFinding::query()->count())->toBe(1)
        ->and(Alert::query()->where('rule', AlertRule::Vulnerability->value)->count())->toBe(1);
});

test('a high severity outside production alerts as a warning', function (): void {
    Security::advisory('GHSA-35jh-r3h4-6jhm', ['severity_label' => 'HIGH', 'cvss' => ['v3' => 'CVSS:3.1/AV:N/AC:L/PR:L/UI:N/S:U/C:H/I:H/A:N', 'v3Score' => 8.1]]);
    $this->instance->environment->forceFill(['kind' => EnvironmentKind::Persistent])->save();

    ($this->evaluate)();

    expect(vulnerabilityAlert()?->severity)->toBe(AlertSeverity::Warning)
        ->and(vulnerabilityAlert()?->title)->toBe('api uses lodash 4.17.20, which has a high-severity vulnerability');
});

test('"Worth knowing" findings never notify', function (): void {
    DB::table('inventory_components')->update(['scope' => 'dev']);

    ($this->evaluate)();

    expect(SecurityFinding::query()->sole()->level)->toBe(FindingLevel::WorthKnowing)
        ->and(SecurityFinding::query()->sole()->reasons)->toBe(['dev'])
        ->and(SecurityFinding::query()->sole()->investigation_id)->toBeNull()
        ->and(Alert::query()->count())->toBe(0);
});

test('a new deployment without the version resolves the finding and the alert; a rollback reopens it', function (): void {
    ($this->evaluate)();
    $finding = SecurityFinding::query()->sole();

    // The next deployment runs a fixed image.
    $fixed = $this->deployment->replicate(['inventory_id']);
    $fixed->forceFill(['public_id' => null, 'status' => 'active', 'image' => '127.0.0.1:5000/apps/x@sha256:'.str_repeat('a', 64)])->save();
    Security::inventory($fixed, [Security::component('npm', 'lodash', '4.17.21')]);
    $this->instance->forceFill(['active_deployment_id' => $fixed->id])->save();

    ($this->evaluate)();
    expect($finding->refresh()->status)->toBe(SecurityFindingStatus::Resolved)
        ->and($finding->resolved_at)->not->toBeNull()
        ->and(vulnerabilityAlert())->toBeNull();

    // Rolling back brings the version and its finding back.
    $this->instance->forceFill(['active_deployment_id' => $this->deployment->id])->save();
    ($this->evaluate)();

    expect($finding->refresh()->status)->toBe(SecurityFindingStatus::Open)
        ->and(SecurityFinding::query()->count())->toBe(1);
});

test('missing or failed OSV answers change nothing', function (): void {
    ($this->evaluate)();
    $finding = SecurityFinding::query()->sole();

    DB::table('advisory_queries')->update(['advisory_ids' => '[]', 'error' => 'osv.dev answered HTTP 400.']);
    ($this->evaluate)();
    expect($finding->refresh()->status)->toBe(SecurityFindingStatus::Open);

    DB::table('advisory_queries')->delete();
    ($this->evaluate)();
    expect($finding->refresh()->status)->toBe(SecurityFindingStatus::Open);
});

test('withdrawn advisories resolve their findings', function (): void {
    ($this->evaluate)();
    $finding = SecurityFinding::query()->sole();

    DB::table('advisories')->update(['withdrawn' => now()]);
    ($this->evaluate)();

    expect($finding->refresh()->status)->toBe(SecurityFindingStatus::Resolved);
});

test('dismissing holds until the finding gets more urgent', function (): void {
    DB::table('inventory_components')->update(['scope' => 'unknown']);
    ($this->evaluate)();
    $finding = SecurityFinding::query()->sole();
    expect($finding->level)->toBe(FindingLevel::WorthKnowing)->and($finding->reasons)->toBe(['unknown_scope']);

    resolve(DismissFinding::class)->handle($finding, new DismissFindingData(DismissReason::NotUsed, "We never call\x07 template()"), $this->owner, AuditActor::user($this->owner));
    expect($finding->refresh()->status)->toBe(SecurityFindingStatus::Dismissed)
        ->and($finding->dismiss_note)->toBe('We never call template()');

    ($this->evaluate)();
    expect($finding->refresh()->status)->toBe(SecurityFindingStatus::Dismissed);

    // CISA adds the CVE: known exploited lifts the unknown scope, the dismissal ends.
    Security::kev('CVE-2021-23337');
    ($this->evaluate)();

    expect($finding->refresh()->status)->toBe(SecurityFindingStatus::Open)
        ->and($finding->level)->toBe(FindingLevel::ActionNeeded)
        ->and($finding->known_exploited)->toBeTrue()
        ->and($finding->known_exploited_since?->toDateString())->toBe('2026-10-03')
        ->and(vulnerabilityAlert()?->severity)->toBe(AlertSeverity::Critical);
});

test('dismissing the last urgent finding resolves the alert', function (): void {
    ($this->evaluate)();
    expect(vulnerabilityAlert())->not->toBeNull();

    resolve(DismissFinding::class)->handle(SecurityFinding::query()->sole(), new DismissFindingData(DismissReason::Mitigated), $this->owner, AuditActor::user($this->owner));

    expect(vulnerabilityAlert())->toBeNull();
});

test('a second advisory of the same service notifies again after the repeat window', function (): void {
    ($this->evaluate)();
    $alert = vulnerabilityAlert();
    $notified = $alert?->notified_at;

    Security::advisory('GHSA-aaaa-bbbb-cccc', ['affected' => [['ecosystem' => 'npm', 'name' => 'lodash', 'fixed' => ['4.17.22']]]]);
    Security::match('npm', 'lodash', '4.17.20', ['GHSA-35jh-r3h4-6jhm', 'GHSA-aaaa-bbbb-cccc']);
    CarbonImmutable::setTestNow('2026-10-20 13:30:30');
    ($this->evaluate)();

    $alert = vulnerabilityAlert();
    expect(SecurityFinding::query()->count())->toBe(2)
        ->and(Alert::query()->where('rule', AlertRule::Vulnerability->value)->count())->toBe(1)
        ->and($alert?->occurrence)->toBe('GHSA-aaaa-bbbb-cccc')
        ->and($alert?->notified_at?->greaterThan($notified))->toBeTrue();
});

test('images launchroom did not build alert only for known exploited advisories', function (): void {
    $this->inventory->forceFill(['source' => InventorySource::Registry])->save();
    DB::table('inventory_components')->update(['scope' => 'unknown']);

    ($this->evaluate)();
    expect(SecurityFinding::query()->sole()->reasons)->toBe(['third_party_image']);

    Security::kev('CVE-2021-23337');
    ($this->evaluate)();
    expect(SecurityFinding::query()->sole()->level)->toBe(FindingLevel::ActionNeeded);
});

test('services without a public domain are "Worth knowing" with the reason', function (): void {
    DB::table('domains')->where('service_instance_id', $this->instance->id)->delete();

    ($this->evaluate)();

    expect(SecurityFinding::query()->sole()->reasons)->toBe(['not_public']);
});

test('preview environments are not evaluated', function (): void {
    $this->instance->environment->forceFill(['kind' => EnvironmentKind::Preview])->save();

    ($this->evaluate)();

    expect(SecurityFinding::query()->count())->toBe(0);
});

test('teams never see each other\'s findings', function (): void {
    ($this->evaluate)();
    $other = Team::factory()->create();

    expect(resolve(EvaluateSecurityFindings::class)->handle($other))->toBe(['open' => 0, 'actionNeeded' => 0, 'resolved' => 0])
        ->and(SecurityFinding::query()->where('team_id', $other->id)->count())->toBe(0)
        ->and(Deployment::query()->whereKey($this->deployment->id)->value('inventory_id'))->toBe($this->inventory->id);
});
