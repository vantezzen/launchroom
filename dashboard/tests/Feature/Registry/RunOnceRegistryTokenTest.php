<?php

declare(strict_types=1);

use App\Actions\Registry\IssueDaemonRegistryToken;
use App\Data\Registry\RegistryTokenRequest;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\ServerRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Server;
use App\Services\Registry\RegistryTokenKeyFile;

/*
| A release command runs the new image before it is in any NodeState: a
| leased run_once task may pull exactly its own service's image (docs/09 R24).
*/

beforeEach(function (): void {
    $this->keyDir = sys_get_temp_dir().'/launchroom-registry-'.bin2hex(random_bytes(6));
    config([
        'launchroom.registry.token_key_path' => $this->keyDir.'/registry-token.es256.pem',
        'launchroom.registry.token_public_key_path' => $this->keyDir.'/public/registry-token.pub.pem',
    ]);
    RegistryTokenKeyFile::create(config('launchroom.registry.token_key_path'), config('launchroom.registry.token_public_key_path'));
    $this->server = Server::factory()->online()->create(['roles' => [ServerRole::Runtime]]);
});

afterEach(function (): void {
    foreach ([config('launchroom.registry.token_key_path'), config('launchroom.registry.token_public_key_path')] as $path) {
        if (is_file($path)) {
            chmod($path, 0600);
            unlink($path);
        }
    }

    @rmdir($this->keyDir.'/public');
    @rmdir($this->keyDir);
});

function runOnceTask(Server $server, string $service, bool $leased = true): DaemonTask
{
    $task = DaemonTask::query()->create(['team_id' => $server->team_id, 'type' => DaemonTaskType::RunOnce, 'spec' => (object) ['service' => $service]]);

    if ($leased) {
        $task->forceFill(['server_id' => $server->id, 'attempt' => 1, 'leased_at' => now(), 'lease_expires_at' => now()->addMinute(), 'deadline_at' => now()->addMinutes(15)]);
        $task->transitionTo(DaemonTaskStatus::Leased);
    }

    return $task;
}

function pullRequestFor(string $repository, string $task, array $actions = ['pull']): RegistryTokenRequest
{
    return RegistryTokenRequest::validateAndCreate([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'RegistryTokenRequest',
        'scopes' => [['repository' => $repository, 'actions' => $actions]], 'task' => $task,
    ]);
}

test('a leased run_once task may pull its own service image', function (): void {
    $task = runOnceTask($this->server, 'svc_01J9Z8X7W6V5T4S3R2Q1P0N9N2');

    $token = resolve(IssueDaemonRegistryToken::class)->handle($this->server, pullRequestFor('apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n2', $task->public_id));

    expect($token->scopes[0]->actions)->toBe(['pull']);
});

test('run_once tasks never push, never pull other services and must be leased by this server', function (string $repository, array $actions, bool $leased): void {
    $task = runOnceTask($this->server, 'svc_01J9Z8X7W6V5T4S3R2Q1P0N9N2', $leased);

    resolve(IssueDaemonRegistryToken::class)->handle($this->server, pullRequestFor($repository, $task->public_id, $actions));
})->with([
    'push' => ['apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n2', ['pull', 'push'], true],
    'other service' => ['apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n3', ['pull'], true],
    'cache' => ['cache/svc_01j9z8x7w6v5t4s3r2q1p0n9n2', ['pull'], true],
    'not leased' => ['apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n2', ['pull'], false],
])->throws(DomainRuleViolation::class);
