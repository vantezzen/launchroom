<?php

declare(strict_types=1);

use App\Actions\Alerts\EvaluateAlertConditions;
use App\Actions\Deployments\DeployEnvironment;
use App\Data\Audit\AuditActor;
use App\Data\Backups\RetentionData;
use App\Data\Domains\DnsCheckData;
use App\Data\Ingress\IngressReportData;
use App\Data\Instance\DashboardCertificateData;
use App\Data\Servers\ControlPlaneHealthData;
use App\Enums\Alerts\AlertRule;
use App\Enums\Alerts\AlertStatus;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\CertFailureKind;
use App\Enums\CertStatus;
use App\Enums\Domains\DnsCheckStatus;
use App\Enums\IngressFailureReason;
use App\Enums\TeamRole;
use App\Models\Alerts\Alert;
use App\Models\Alerts\AlertRuleSetting;
use App\Models\Backups\BackupRun;
use App\Models\Backups\BackupSchedule;
use App\Models\Domain;
use App\Models\InstanceSettings;
use App\Models\Placement;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Services\Alerts\Conditions\QueueBacklog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Alerts;
use Tests\Support\Backups;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Alerts::fakeDns();
    Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
    CarbonImmutable::setTestNow('2026-11-17 12:00:30');
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    Alerts::channel($this->team, $this->owner);
    [$this->server] = Projects::server($this->team);
    $this->server->forceFill(['name' => 'fra-1', 'is_control_plane' => true])->save();
    $this->project = Projects::create($this->team, 'Shop');
    Projects::image($this->project, 'web');
    resolve(DeployEnvironment::class)->handle($this->project->production()->sole(), null, AuditActor::system());
    $this->instance = ServiceInstance::query()->whereHas('environment', fn (Builder $q) => $q->where('project_id', $this->project->id))->sole();
    $this->placement = Placement::query()->where('service_instance_id', $this->instance->id)->sole();
    $this->instance->forceFill(['active_deployment_id' => $this->placement->deployment_id])->save();
    $this->evaluate = fn (): array => resolve(EvaluateAlertConditions::class)->handle();
    $this->firing = fn (AlertRule $rule): ?Alert => Alert::query()->where('rule', $rule->value)->where('status', AlertStatus::Firing->value)->first();
    $this->turnOn = fn (AlertRule $rule) => AlertRuleSetting::query()->create(['team_id' => $this->team->id, 'rule' => $rule, 'enabled' => true]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('service_crashed and cron_failed are opt-in, the other new rules are on', function (): void {
    foreach (AlertRule::cases() as $rule) {
        expect($rule->enabledByDefault())->toBe(! in_array($rule, [AlertRule::ServiceCrashed, AlertRule::CronFailed], true));
    }
});

test('ingress_down fires after 2 minutes with the port and the program, and resolves once routes apply', function (): void {
    $down = fn (string $since): IngressReportData => new IngressReportData(state: 'failed', routes: 3, reason: IngressFailureReason::PortInUse, port: 80, process: 'nginx', failingSince: $since);

    $this->server->forceFill(['ingress_status' => $down(now()->subMinute()->toIso8601ZuluString())])->save();
    ($this->evaluate)();
    expect(($this->firing)(AlertRule::IngressDown))->toBeNull();

    $this->server->forceFill(['ingress_status' => $down(now()->subMinutes(3)->toIso8601ZuluString())])->save();
    ($this->evaluate)();
    $alert = ($this->firing)(AlertRule::IngressDown);
    expect($alert?->title)->toBe('fra-1 isn’t serving your domains')
        ->and($alert?->summary)->toContain('nginx is using it')
        ->and($alert?->summary)->toContain('sudo systemctl disable --now nginx')
        ->and($alert?->facts)->toMatchArray(['Port' => '80', 'Program' => 'nginx']);

    $this->server->forceFill(['ingress_status' => new IngressReportData(state: 'applied', routes: 3)])->save();
    ($this->evaluate)();
    expect(($this->firing)(AlertRule::IngressDown))->toBeNull()
        ->and($alert?->refresh()->resolve_reason)->toBe('It serves its routes again.');
});

test('certificate_failed fires at once on a rate limit and 30 minutes after DNS is right otherwise', function (): void {
    $domain = Domain::query()->create(['team_id' => $this->team->id, 'service_instance_id' => $this->instance->id, 'hostname' => 'shop.example.com', 'kind' => 'custom']);
    $dnsOk = new DnsCheckData(DnsCheckStatus::Ok, 'shop.example.com', ['203.0.113.7'], ['203.0.113.7'], null, 'Points here', now()->toIso8601ZuluString());
    $domain->forceFill(['cert_status' => CertStatus::Failed, 'cert_error' => 'timeout during connect', 'cert_failure_kind' => CertFailureKind::Timeout, 'verification' => $dnsOk])->save();

    ($this->evaluate)();
    expect(($this->firing)(AlertRule::CertificateFailed))->toBeNull();

    CarbonImmutable::setTestNow('2026-11-17 12:31:00');
    ($this->evaluate)();
    $alert = ($this->firing)(AlertRule::CertificateFailed);
    expect($alert?->title)->toBe('shop.example.com has no certificate')
        ->and($alert?->summary)->toContain('port 80 or 443');

    $domain->forceFill(['cert_status' => CertStatus::Issued, 'cert_error' => null, 'cert_failure_kind' => null])->save();
    ($this->evaluate)();
    expect(($this->firing)(AlertRule::CertificateFailed))->toBeNull();

    $other = Domain::query()->create(['team_id' => $this->team->id, 'service_instance_id' => $this->instance->id, 'hostname' => 'api.example.com', 'kind' => 'custom']);
    $other->forceFill(['cert_status' => CertStatus::Failed, 'cert_error' => 'rateLimited', 'cert_failure_kind' => CertFailureKind::RateLimited, 'cert_retry_after' => now()->addDay()])->save();
    ($this->evaluate)();
    expect(($this->firing)(AlertRule::CertificateFailed)?->facts)->toHaveKey('Next try');
});

test('service_crashed fires on a crash of the live service when turned on, and resolves after a quiet hour', function (): void {
    DB::table('service_crashes')->insert([
        'team_id' => $this->team->id, 'service_instance_id' => $this->instance->id, 'deployment_id' => $this->placement->deployment_id,
        'server_id' => $this->server->id, 'workload_id' => $this->placement->workload_id, 'at' => now()->subMinutes(2), 'exit_code' => 137, 'oom_killed' => true,
    ]);

    ($this->evaluate)();
    expect(($this->firing)(AlertRule::ServiceCrashed))->toBeNull(); // opt-in

    ($this->turnOn)(AlertRule::ServiceCrashed);
    ($this->evaluate)();
    $alert = ($this->firing)(AlertRule::ServiceCrashed);
    expect($alert?->title)->toBe('web crashed in production')
        ->and($alert?->summary)->toContain('ran out of memory')
        ->and($alert?->facts)->toBe(['Crashes' => '1 in the last hour']);

    CarbonImmutable::setTestNow('2026-11-17 13:00:00');
    ($this->evaluate)();
    expect(($this->firing)(AlertRule::ServiceCrashed))->toBeNull();
});

test('cron_failed fires on a failed run and resolves when the next one succeeds', function (): void {
    Service::query()->whereKey($this->instance->service_id)->update(['kind' => 'cron']);
    ($this->turnOn)(AlertRule::CronFailed);
    $run = fn (string $start, int $code) => DB::table('cron_runs')->insert([
        'team_id' => $this->team->id, 'service_instance_id' => $this->instance->id, 'workload_id' => $this->placement->workload_id,
        'started_at' => $start, 'finished_at' => CarbonImmutable::parse($start)->addSeconds(5), 'exit_code' => $code, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $run('2026-11-17 11:00:00', 2);
    ($this->evaluate)();
    $alert = ($this->firing)(AlertRule::CronFailed);
    expect($alert?->subject_key)->toBe('cron:'.$this->instance->public_id)
        ->and($alert?->title)->toBe('web failed in production');

    $run('2026-11-17 12:00:00', 0);
    ($this->evaluate)();
    expect(($this->firing)(AlertRule::CronFailed))->toBeNull();
});

test('control_plane_degraded fires on a watchdog restart or a dashboard that isn’t ready, and resolves when all runs', function (): void {
    $health = fn (array $report, ?ControlPlaneHealthData $previous = null): ControlPlaneHealthData => ControlPlaneHealthData::merge($previous, ['checkedAt' => now()->toIso8601ZuluString(), ...$report], CarbonImmutable::now());

    $this->server->forceFill(['control_plane_health' => $health(['ready' => true, 'restarts' => [['service' => 'horizon', 'at' => now()->subMinutes(10)->toIso8601ZuluString(), 'reason' => 'unhealthy']]])])->save();
    ($this->evaluate)();
    $alert = ($this->firing)(AlertRule::ControlPlaneDegraded);
    expect($alert?->summary)->toContain('launchroom restarted Horizon at 11:50 UTC: its health check failed 3 times.');

    CarbonImmutable::setTestNow('2026-11-17 13:00:00');
    $once = $health(['ready' => false], $this->server->refresh()->control_plane_health);
    $this->server->forceFill(['control_plane_health' => $once])->save();
    ($this->evaluate)();
    expect(($this->firing)(AlertRule::ControlPlaneDegraded))->toBeNull(); // one report isn't enough

    $this->server->forceFill(['control_plane_health' => $health(['ready' => false], $once)])->save();
    ($this->evaluate)();
    expect(($this->firing)(AlertRule::ControlPlaneDegraded)?->summary)->toContain('doesn’t answer its readiness check');

    $this->server->forceFill(['control_plane_health' => $health(['ready' => true])])->save();
    ($this->evaluate)();
    expect(($this->firing)(AlertRule::ControlPlaneDegraded))->toBeNull();
});

test('queue_backlog fires when the oldest waiting job is older than 5 minutes', function (): void {
    $queue = Mockery::mock(Queue::class);
    // One queue (default): 12 minutes old on the first evaluation, empty on the second.
    $queue->shouldReceive('creationTimeOfOldestPendingJob')->andReturn(now()->subMinutes(12)->getTimestamp(), null);
    // Only the condition reads this factory; alert deliveries keep the real queue.
    $factory = new readonly class($queue) implements QueueFactory
    {
        public function __construct(private Queue $queue) {}

        public function connection($name = null): Queue
        {
            return $this->queue;
        }
    };
    $this->app->instance(QueueBacklog::class, new QueueBacklog($factory));

    ($this->evaluate)();
    $alert = ($this->firing)(AlertRule::QueueBacklog);
    expect($alert?->title)->toBe('Background work is falling behind')
        ->and($alert?->facts)->toBe(['Queue default' => 'oldest job waits 12 min']);

    ($this->evaluate)();
    expect(($this->firing)(AlertRule::QueueBacklog))->toBeNull();
});

test('a failed launchroom backup alerts without touching service backup alerts', function (): void {
    Backups::fakeEnvironment();
    // Before the passphrase exists launchroom's backup doesn't run, so it can't fail.
    InstanceSettings::current()->forceFill(['recovery_public_key' => base64_encode(random_bytes(32)), 'recovery_passphrase_set_at' => now()->subDays(2)])->save();
    $destination = Backups::destination(null, $this->owner);
    $schedule = BackupSchedule::query()->where('subject', BackupSubject::ControlPlane->value)->first()
        ?? BackupSchedule::query()->create(['subject' => BackupSubject::ControlPlane, 'destination_id' => $destination->id, 'cron' => '0 3 * * *', 'retention' => RetentionData::controlPlane(), 'enabled' => true]);
    $run = BackupRun::query()->create(['schedule_id' => $schedule->id, 'destination_id' => $schedule->destination_id, 'subject' => BackupSubject::ControlPlane, 'reason' => BackupReason::Scheduled]);
    $run->forceFill(['status' => BackupRunStatus::Failed, 'finished_at' => now()->subHour()])->save();
    $service = Alert::query()->create(['team_id' => $this->team->id, 'rule' => AlertRule::BackupFailed, 'subject_key' => 'failed:'.$this->instance->public_id, 'severity' => 'critical', 'title' => 'Backup of web failed', 'summary' => '-', 'opened_at' => now(), 'last_seen_at' => now()]);

    ($this->evaluate)();

    $alert = Alert::query()->where('subject_key', 'control_plane:backup')->where('status', AlertStatus::Firing->value)->sole();
    expect($alert->title)->toBe('launchroom’s own backup failed')
        ->and($alert->occurrence)->toBe($run->public_id)
        ->and($service->refresh()->status)->toBe(AlertStatus::Firing);
});

test('the dashboard’s own certificate alerts before it expires (G38)', function (): void {
    $this->server->forceFill(['ingress_status' => new IngressReportData(state: 'applied', routes: 1, dashboardCertificate: new DashboardCertificateData(
        state: CertStatus::Issued, notAfter: now()->addDays(5)->toIso8601ZuluString(), lastError: 'urn:ietf:params:acme:error:dns',
    ), dashboardHost: 'launchroom.example.com')])->save();

    ($this->evaluate)();

    $alert = Alert::query()->where('subject_key', 'dashboard:certificate')->where('status', AlertStatus::Firing->value)->sole();
    expect($alert->title)->toBe('The dashboard’s certificate for launchroom.example.com is about to expire')
        ->and($alert->server_id)->toBe($this->server->id);
});
