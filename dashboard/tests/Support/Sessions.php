<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use Tests\TestCase;

/**
 * Fixtures for interactive session tests (docs/03 §11): a running Postgres
 * on a server whose signing seed and sealing key pair the test knows.
 */
final class Sessions
{
    /**
     * @return array{team: Team, server: Server, keypair: string, instance: ServiceInstance, daemon: FakeDaemon, spy: SpyBroadcaster}
     */
    public static function world(): array
    {
        Projects::useTestKek();
        $spy = SpyBroadcaster::install();
        [$team, $server, $keypair, $instance] = Backups::runningPostgres();

        $seed = random_bytes(32);
        $server->forceFill([
            'signing_public_key' => Server::factory()->withSigningSeed($seed)->make()->signing_public_key,
            'daemon_features' => ['consoleSessions', 'dataSessions'],
        ])->save();

        return [
            'team' => $team,
            'server' => $server->refresh(),
            'keypair' => $keypair,
            'instance' => $instance->load('service', 'environment.project'),
            'daemon' => new FakeDaemon($seed)->as($server->public_id),
            'spy' => $spy,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string} project, environment, service public ids
     */
    public static function route(ServiceInstance $instance): array
    {
        return [$instance->environment->project->public_id, $instance->environment->public_id, $instance->service->public_id];
    }

    public static function url(ServiceInstance $instance, string $kind): string
    {
        [$project, $environment, $service] = self::route($instance);

        return "/sessions/p/{$project}/{$environment}/s/{$service}/{$kind}";
    }

    /**
     * Opens a session as $user and returns the JSON answer.
     *
     * @return array<string, mixed>
     */
    public static function open(TestCase $test, User $user, ServiceInstance $instance, string $kind = 'console', array $body = []): array
    {
        $response = $test->actingAs($user)->postJson(self::url($instance, $kind), $body);
        $response->assertCreated();

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }

    /**
     * @return array<string, mixed> the leased task as the daemon sees it
     */
    public static function lease(TestCase $test, FakeDaemon $daemon): array
    {
        $tasks = $daemon->send($test, 'POST', '/daemon/v1/tasks/lease', ['apiVersion' => 'launchroom.vantezzen.io/v1', 'kind' => 'LeaseRequest', 'max' => 4, 'types' => ['session']])
            ->assertOk()
            ->json('tasks');

        /** @var array<string, mixed> $task */
        $task = is_array($tasks) && isset($tasks[0]) && is_array($tasks[0]) ? $tasks[0] : [];

        return $task;
    }

    public static function member(Team $team, TeamRole $role): User
    {
        return memberOf($team, $role);
    }
}
