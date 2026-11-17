<?php

declare(strict_types=1);

use App\Actions\Registry\IssueDaemonRegistryToken;
use App\Data\Registry\RegistryTokenRequest;
use App\Data\Servers\NodeStateFacts;
use App\Enums\DaemonTaskStatus;
use App\Enums\DaemonTaskType;
use App\Enums\ServerRole;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\NodeStateVersion;
use App\Models\Server;
use App\Models\Team;
use App\Services\Registry\RegistryTokenKeyFile;

/*
| Team mirrors (D91): private images copied with a team's sign-in details
| live under mirror/team_<id>/…; only that team's servers that run (or hold
| a task naming) the image may pull it, and nobody pushes.
*/

beforeEach(function (): void {
    $this->keyDir = sys_get_temp_dir().'/launchroom-registry-'.bin2hex(random_bytes(6));
    config([
        'launchroom.registry.token_key_path' => $this->keyDir.'/registry-token.es256.pem',
        'launchroom.registry.token_public_key_path' => $this->keyDir.'/public/registry-token.pub.pem',
    ]);
    RegistryTokenKeyFile::create(config('launchroom.registry.token_key_path'), config('launchroom.registry.token_public_key_path'));

    $this->teamA = Team::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->serverA = Server::factory()->online()->create(['team_id' => $this->teamA->id, 'roles' => [ServerRole::Runtime]]);
    $this->serverB = Server::factory()->online()->create(['team_id' => $this->teamB->id, 'roles' => [ServerRole::Runtime]]);
    $this->repoA = 'mirror/'.strtolower($this->teamA->public_id).'/ghcr.io/acme/api';
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

function runsImage(Server $server, string $repository): void
{
    NodeStateVersion::query()->create([
        'server_id' => $server->id, 'version' => 1, 'created_at' => now(),
        'document' => '{}', 'document_hash' => str_repeat('0', 64),
        'facts' => new NodeStateFacts(images: ['127.0.0.1:5000/'.$repository.'@sha256:'.str_repeat('a', 64)]),
    ]);
}

function mirrorRequest(string $repository, array $actions = ['pull'], ?string $task = null): RegistryTokenRequest
{
    return RegistryTokenRequest::validateAndCreate(array_filter([
        'apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'RegistryTokenRequest',
        'scopes' => [['repository' => $repository, 'actions' => $actions]], 'task' => $task,
    ], static fn (mixed $value): bool => $value !== null));
}

test('a server of the team that runs the image may pull its team mirror, never push', function (): void {
    runsImage($this->serverA, $this->repoA);
    $issue = resolve(IssueDaemonRegistryToken::class);

    expect($issue->handle($this->serverA, mirrorRequest($this->repoA))->scopes[0]->actions)->toBe(['pull']);
    expect(fn () => $issue->handle($this->serverA, mirrorRequest($this->repoA, ['pull', 'push'])))->toThrow(DomainRuleViolation::class);
});

test('another team’s server never gets a team mirror, even when its NodeState lists the image', function (): void {
    runsImage($this->serverB, $this->repoA);

    resolve(IssueDaemonRegistryToken::class)->handle($this->serverB, mirrorRequest($this->repoA));
})->throws(DomainRuleViolation::class);

test('a server of the team that does not run the image is refused', function (): void {
    runsImage($this->serverA, 'mirror/'.strtolower($this->teamA->public_id).'/ghcr.io/acme/other');

    resolve(IssueDaemonRegistryToken::class)->handle($this->serverA, mirrorRequest($this->repoA));
})->throws(DomainRuleViolation::class);

test('team prefixes never pass as public mirror paths and wildcards are never granted', function (string $repository): void {
    runsImage($this->serverA, $this->repoA);

    resolve(IssueDaemonRegistryToken::class)->handle($this->serverA, mirrorRequest($repository));
})->with([
    'team root' => ['mirror/team_01j9z8x7w6v5t4s3r2q1p0n9n2/ghcr.io'],
    'host without a dot' => ['mirror/registry/acme/api'],
])->throws(DomainRuleViolation::class);

test('a leased run_once task naming the team image may pull it before any NodeState has it', function (): void {
    $task = DaemonTask::query()->create(['team_id' => $this->teamA->id, 'type' => DaemonTaskType::RunOnce, 'spec' => (object) [
        'service' => 'svc_01J9Z8X7W6V5T4S3R2Q1P0N9N2', 'environment' => 'env_01j9z8x7w6v5t4s3r2q1p0n9n3', 'deployment' => 'dep_01j9z8x7w6v5t4s3r2q1p0n9n4',
        'image' => '127.0.0.1:5000/'.$this->repoA.'@sha256:'.str_repeat('b', 64),
        'network' => ['id' => 'net', 'name' => 'lr-e_net', 'subnet' => '10.210.0.0/24'], 'timeout' => '15m',
        'resources' => ['cpus' => 1.0, 'memoryMb' => 512, 'pids' => 256],
    ]]);
    $task->forceFill(['server_id' => $this->serverA->id, 'attempt' => 1, 'leased_at' => now(), 'lease_expires_at' => now()->addMinute(), 'deadline_at' => now()->addMinutes(15)]);
    $task->transitionTo(DaemonTaskStatus::Leased);

    $issue = resolve(IssueDaemonRegistryToken::class);

    expect($issue->handle($this->serverA, mirrorRequest($this->repoA, task: $task->public_id))->scopes[0]->repository)->toBe($this->repoA);
    expect(fn () => $issue->handle($this->serverA, mirrorRequest('mirror/'.strtolower($this->teamA->public_id).'/ghcr.io/acme/other', task: $task->public_id)))->toThrow(DomainRuleViolation::class);
});
