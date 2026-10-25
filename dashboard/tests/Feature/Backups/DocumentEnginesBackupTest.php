<?php

declare(strict_types=1);

use App\Actions\Backups\StartDatabaseBackup;
use App\Data\Audit\AuditActor;
use App\Enums\Backups\BackupReason;
use App\Enums\DaemonFeature;
use App\Enums\TeamRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Variable;
use Tests\Support\Backups;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Backups::fakeEnvironment();
});

test('MongoDB and ClickHouse backups go to launchroomd versions that know the engine (D47)', function (string $engine, string $version): void {
    [$team, $server, , $instance] = Backups::runningPostgres(null, $engine, $version);
    $owner = memberOf($team, TeamRole::Owner);
    Backups::destination($team, $owner);

    $server->forceFill(['daemon_features' => []])->save();

    expect(fn () => resolve(StartDatabaseBackup::class)->handle($instance->fresh(), BackupReason::Manual, $owner, AuditActor::user($owner)))
        ->toThrow(DomainRuleViolation::class, 'in Settings → Updates');

    $server->forceFill(['daemon_features' => [DaemonFeature::MongoClickHouse->value]])->save();
    $run = resolve(StartDatabaseBackup::class)->handle($instance->fresh(), BackupReason::Manual, $owner, AuditActor::user($owner));
    $task = DaemonTask::query()->findOrFail($run->daemon_task_id);

    expect(data_get($task->spec, 'target.engine'))->toBe($engine)
        ->and(data_get($task->spec, 'tags'))->toContain('engine:'.$engine);
})->with([['mongodb', '7.0'], ['clickhouse', '26.8']]);

test('a managed MongoDB gets generated credentials per environment', function (): void {
    [, , , $instance] = Backups::runningPostgres(null, 'mongodb', '8.0');

    $keys = Variable::query()->where('service_id', $instance->service_id)->pluck('key')->all();

    expect($keys)->toContain('MONGO_INITDB_ROOT_PASSWORD', 'MONGO_URL', 'MONGOHOST')
        ->and($instance->volumes()->sole()->mount_path)->toBe('/data/db');
});
