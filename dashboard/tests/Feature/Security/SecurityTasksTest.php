<?php

declare(strict_types=1);

use App\Actions\Daemons\CompleteTask;
use App\Actions\Daemons\LeaseTasks;
use App\Actions\Registry\IssueDaemonRegistryToken;
use App\Actions\Security\RequestInventory;
use App\Actions\Security\StartInvestigation;
use App\Data\Protocol\ProtocolJson;
use App\Data\Protocol\V1\LeaseRequest;
use App\Data\Protocol\V1\Task;
use App\Data\Protocol\V1\TaskCompletion;
use App\Data\Protocol\V1\TaskLease;
use App\Data\Registry\RegistryTokenRequest;
use App\Data\Servers\HostFactsData;
use App\Data\Services\GitSource;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\Security\InventorySource;
use App\Enums\Security\InventoryStatus;
use App\Enums\Security\InvestigationStatus;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Placement;
use App\Models\Security\ImageInventory;
use App\Models\Security\SecurityInvestigation;
use App\Models\Server;
use App\Models\Team;
use App\Services\Registry\RegistryTokenKeyFile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;
use Tests\Support\Security;

/*
| Security center tasks (docs/22 §3.2, §7.2, docs/03 §13.1–13.2) with faked
| completions from the protocol fixtures: inventory task creation, leasing
| by feature and platform, the registry pull token, completion handling;
| container inspections stored encrypted and turned into a report.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Security::fakeDns();
    Http::fake(['api.osv.dev/*' => Http::response(['results' => []])]);
    CarbonImmutable::setTestNow('2026-10-17 12:00:00');
    config(['launchroom.git.plain_sources' => true, 'launchroom.git.insecure_hosts' => ['git-fixtures']]);
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->server = Security::server($this->team);
    $this->project = Projects::create($this->team, 'Shop');
    [$this->service, $this->instance, $this->deployment] = Security::liveService($this->project, 'api');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function completion(string $fixture, ?string $image = null): array
{
    $document = json_decode((string) file_get_contents(ProtocolSchemas::root()."/fixtures/v1/{$fixture}.json"), true);

    if ($image !== null) {
        $document['inventory']['image'] = $image;
    }

    return $document;
}

/**
 * @return list<Task>
 */
function leaseFor(Server $server, string $type): array
{
    return resolve(LeaseTasks::class)->handle($server->refresh(), LeaseRequest::from(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 4, 'types' => [$type]]));
}

test('a live image of a registry gets an inventory task without source', function (): void {
    $inventory = resolve(RequestInventory::class)->handle($this->deployment);
    $task = $inventory?->task;

    expect($inventory?->status)->toBe(InventoryStatus::Pending)
        ->and($inventory?->source)->toBe(InventorySource::Registry)
        ->and($this->deployment->refresh()->inventory_id)->toBe($inventory?->id)
        ->and($task?->type)->toBe(DaemonTaskType::Inventory)
        ->and($task?->max_attempts)->toBe(3)
        ->and($task?->timeout_seconds)->toBe(900)
        ->and((array) $task?->spec)->toBe(['image' => $this->deployment->image, 'platform' => 'linux/arm64']);

    // The same image again only links.
    expect(resolve(RequestInventory::class)->handle($this->deployment)?->id)->toBe($inventory?->id)
        ->and(ImageInventory::query()->count())->toBe(1);
});

test('built images carry their service and the build\'s commit', function (): void {
    $this->service->forceFill(['source' => GitSource::from(['provider' => 'git', 'repositoryId' => 0, 'fullName' => 'fixtures/node-hello', 'cloneUrl' => 'http://git-fixtures:8080/node-hello.git', 'branch' => 'main', 'rootDirectory' => 'apps/web'])])->save();
    $this->deployment->forceFill(['image' => '127.0.0.1:5000/apps/'.strtolower($this->service->public_id).'@'.Security::DIGEST, 'commit_sha' => str_repeat('4', 40)])->save();

    $task = resolve(RequestInventory::class)->handle($this->deployment)?->task;

    expect(json_decode((string) json_encode($task?->spec), true))->toEqual([
        'image' => $this->deployment->image,
        'platform' => 'linux/arm64',
        'service' => $this->service->public_id,
        'source' => ['repo' => ['provider' => 'git', 'cloneUrl' => 'http://git-fixtures:8080/node-hello.git', 'sha' => str_repeat('4', 40)], 'rootDir' => 'apps/web'],
    ]);

    // An apps/ image of another service is never inventoried under this one.
    $this->deployment->forceFill(['image' => '127.0.0.1:5000/apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n3@sha256:'.str_repeat('b', 64)])->save();
    expect(resolve(RequestInventory::class)->handle($this->deployment))->toBeNull();
});

test('inventory tasks are leased by build servers of the platform that announce the feature, schema-valid', function (): void {
    resolve(RequestInventory::class)->handle($this->deployment);

    $this->server->forceFill(['daemon_features' => [DaemonFeature::Waf->value]])->save();
    expect(leaseFor($this->server, 'inventory'))->toBe([]);

    $this->server->forceFill(['daemon_features' => [DaemonFeature::Inventory->value], 'facts' => new HostFactsData('host', 'linux', 'amd64', 4, 8192)])->save();
    expect(leaseFor($this->server, 'inventory'))->toBe([]);

    $this->server->forceFill(['facts' => new HostFactsData('host', 'linux', 'arm64', 4, 8192)])->save();
    $leased = leaseFor($this->server, 'inventory');

    expect($leased)->toHaveCount(1)
        ->and(ProtocolSchemas::errors('task-lease', ProtocolJson::encode(new TaskLease($leased))))->toBe([]);
});

test('a leased inventory task may pull its service\'s image, nothing more', function (): void {
    $dir = sys_get_temp_dir().'/launchroom-sec-'.bin2hex(random_bytes(4));
    config(['launchroom.registry.token_key_path' => $dir.'/k.pem', 'launchroom.registry.token_public_key_path' => $dir.'/public/k.pub.pem']);
    RegistryTokenKeyFile::create(config('launchroom.registry.token_key_path'), config('launchroom.registry.token_public_key_path'));
    $this->service->forceFill(['source' => GitSource::from(['provider' => 'git', 'repositoryId' => 0, 'fullName' => 'fixtures/x', 'cloneUrl' => 'http://git-fixtures:8080/x.git'])])->save();
    $repo = 'apps/'.strtolower($this->service->public_id);
    $this->deployment->forceFill(['image' => '127.0.0.1:5000/'.$repo.'@'.Security::DIGEST])->save();
    resolve(RequestInventory::class)->handle($this->deployment);
    $task = leaseFor($this->server, 'inventory')[0];
    $issue = fn (string $action) => resolve(IssueDaemonRegistryToken::class)->handle($this->server, RegistryTokenRequest::from(['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'RegistryTokenRequest', 'task' => $task->id, 'scopes' => [['repository' => $repo, 'actions' => [$action]]]]));

    expect($issue('pull')->scopes[0]->repository)->toBe($repo)
        ->and(fn () => $issue('push'))->toThrow(DomainRuleViolation::class);

    foreach ([config('launchroom.registry.token_key_path'), config('launchroom.registry.token_public_key_path')] as $path) {
        @chmod($path, 0600);
        @unlink($path);
    }
});

test('a completed inventory stores its components with their OSV packages and links deployments', function (): void {
    $fixture = completion('task-completion.inventory', '127.0.0.1:5000/apps/'.strtolower($this->service->public_id).'@'.Security::DIGEST);
    $this->deployment->forceFill(['image' => $fixture['inventory']['image']])->save();
    // The fixture was scanned for linux/amd64.
    $this->server->forceFill(['facts' => new HostFactsData('host', 'linux', 'amd64', 4, 8192)])->save();
    $inventory = resolve(RequestInventory::class)->handle($this->deployment);
    $task = DaemonTask::query()->findOrFail($inventory?->task_id);
    leaseFor($this->server, 'inventory');

    resolve(CompleteTask::class)->handle($this->server, $task->refresh(), TaskCompletion::validateAndCreate($fixture));

    $inventory = $inventory?->refresh();
    expect($task->refresh()->status)->toBe(DaemonTaskStatus::Succeeded)
        ->and($task->result)->toBe(['inventory' => ['components' => 8]])
        ->and($inventory?->status)->toBe(InventoryStatus::Ready)
        ->and($inventory?->component_count)->toBe(8)
        ->and($inventory?->unqueried_count)->toBe(0)
        ->and($inventory?->distro)->toBe(['id' => 'debian', 'versionId' => '12'])
        ->and($inventory?->notes)->toBe(['1 component had a name or version launchroom couldn\'t read.'])
        ->and(DB::table('inventory_components')->where('name', 'libssl3')->first())->toMatchObject(['osv_ecosystem' => 'Debian:12', 'osv_name' => 'openssl'])
        ->and(DB::table('inventory_components')->where('name', 'node')->value('osv_ecosystem'))->toBe('Bitnami')
        ->and($inventory?->checked_at)->not->toBeNull();
});

test('an inventory for another image fails the task and the inventory', function (): void {
    $this->server->forceFill(['facts' => new HostFactsData('host', 'linux', 'amd64', 4, 8192)])->save();
    $inventory = resolve(RequestInventory::class)->handle($this->deployment);
    leaseFor($this->server, 'inventory');
    $task = DaemonTask::query()->findOrFail($inventory?->task_id);

    resolve(CompleteTask::class)->handle($this->server, $task, TaskCompletion::validateAndCreate(completion('task-completion.inventory')));

    expect($task->refresh()->status)->toBe(DaemonTaskStatus::Failed)
        ->and($task->error)->toBe('The inventory is for another image or platform than the task asked for.')
        ->and($inventory?->refresh()->status)->toBe(InventoryStatus::Failed)
        ->and(DB::table('inventory_components')->count())->toBe(0);
});

test('a component that fails its grammar refuses the whole inventory', function (): void {
    $fixture = completion('task-completion.inventory', '127.0.0.1:5000/apps/'.strtolower($this->service->public_id).'@'.Security::DIGEST);
    $fixture['inventory']['components'][6]['presence'] = 'bundled';
    $this->deployment->forceFill(['image' => $fixture['inventory']['image']])->save();
    $this->server->forceFill(['facts' => new HostFactsData('host', 'linux', 'amd64', 4, 8192)])->save();
    $inventory = resolve(RequestInventory::class)->handle($this->deployment);
    leaseFor($this->server, 'inventory');
    $task = DaemonTask::query()->findOrFail($inventory?->task_id);

    resolve(CompleteTask::class)->handle($this->server, $task, TaskCompletion::validateAndCreate($fixture));

    expect($task->refresh()->status)->toBe(DaemonTaskStatus::Failed)
        ->and($task->error)->toContain('inventory.components.6.presence bundled is only valid for npm packages');
});

test('an investigation inspects each running replica and stores the result encrypted', function (): void {
    Projects::report($this->server);
    $placement = Placement::query()->where('service_instance_id', $this->instance->id)->sole();
    $placement->forceFill(['phase' => 'healthy'])->save();

    $investigation = resolve(StartInvestigation::class)->handle($this->instance, null, $this->owner);
    $task = DaemonTask::query()->where('type', DaemonTaskType::InspectContainer->value)->sole();

    expect($investigation?->status)->toBe(InvestigationStatus::Running)
        ->and($task->pinned_to_server)->toBeTrue()
        ->and($task->server_id)->toBe($this->server->id)
        ->and($task->max_attempts)->toBe(1)
        ->and($task->spec->workload)->toBe($placement->workload_id);

    $leased = leaseFor($this->server, 'inspect_container');
    expect(ProtocolSchemas::errors('task-lease', ProtocolJson::encode(new TaskLease($leased))))->toBe([]);

    $fixture = completion('task-completion.container-inspection');
    $fixture['containerInspection']['workload'] = $placement->workload_id;
    resolve(CompleteTask::class)->handle($this->server, $task->refresh(), TaskCompletion::validateAndCreate($fixture));

    $investigation = $investigation?->refresh();
    $raw = DB::table('security_investigations')->where('id', $investigation?->id)->value('inspections');
    $report = $investigation?->report ?? [];

    expect($task->refresh()->status)->toBe(DaemonTaskStatus::Succeeded)
        ->and($task->result)->toBe(['containerInspection' => ['stored' => true]])
        ->and((string) $raw)->not->toContain('kworker')
        ->and($investigation?->status)->toBe(InvestigationStatus::Completed)
        ->and(json_encode($report))->not->toContain('pool.example')
        ->and(collect($report['found'])->where('level', 'concerning')->pluck('text')->all())->toContain(
            'A new executable file appeared in /tmp: /tmp/.x/kworker (2.1 MB, added 13 h ago).',
            'A process runs from a temporary directory: ./kworker.',
        )
        ->and(collect($report['steps'])->pluck('kind')->all())->toContain('redeploy');
});

test('replicas on servers that can\'t inspect make the report partial', function (): void {
    Projects::report($this->server);
    Placement::query()->where('service_instance_id', $this->instance->id)->update(['phase' => 'healthy']);
    $this->server->forceFill(['status' => ServerStatus::Offline])->save();

    $investigation = resolve(StartInvestigation::class)->handle($this->instance, null, $this->owner)?->refresh();

    expect($investigation?->status)->toBe(InvestigationStatus::Partial)
        ->and($investigation?->report['checked'][0]['note'] ?? null)->toBe("Couldn’t check: {$this->server->name} is offline.")
        ->and(DaemonTask::query()->where('type', DaemonTaskType::InspectContainer->value)->count())->toBe(0);
});

test('manual investigations are throttled per service', function (): void {
    for ($i = 0; $i < 6; $i++) {
        resolve(StartInvestigation::class)->handle($this->instance, null, $this->owner);
    }

    expect(fn () => resolve(StartInvestigation::class)->handle($this->instance, null, $this->owner))->toThrow(DomainRuleViolation::class)
        ->and(SecurityInvestigation::query()->count())->toBe(6);
});
