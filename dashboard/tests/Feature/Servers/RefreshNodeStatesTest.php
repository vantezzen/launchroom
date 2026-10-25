<?php

declare(strict_types=1);

use App\Actions\Servers\RefreshNodeStates;
use App\Jobs\RenderServerNodeState;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/*
| After an update the dashboard brings new system image pins (a new
| LAUNCHROOM_CADDY_IMAGE turns the web firewall on, docs/22 §6.1): every
| approved server's NodeState is rendered again once, and only once.
*/

/** Releases the unique locks of queued renders, as processing them would. */
function releaseNodeStateRenderLocks(): void
{
    foreach (Server::query()->pluck('id') as $id) {
        Cache::lock('laravel_unique_job:'.RenderServerNodeState::class.':'.$id)->forceRelease();
    }
}

test('a changed system image pin re-renders every approved server once', function (): void {
    Queue::fake();
    $online = Server::factory()->online()->count(2)->create();
    Server::factory()->create(); // pending approval: gets no desired state

    expect(resolve(RefreshNodeStates::class)->handle())->toBe(2);
    Queue::assertPushed(RenderServerNodeState::class, 2);
    Queue::assertPushed(RenderServerNodeState::class, fn (RenderServerNodeState $job): bool => $job->serverId === $online[0]->id);

    // Same configuration: nothing to do, no matter how often the scheduler runs.
    expect(resolve(RefreshNodeStates::class)->handle())->toBe(0);
    Queue::assertPushed(RenderServerNodeState::class, 2);

    releaseNodeStateRenderLocks(); // the queued renders ran (RenderServerNodeState is unique until processing)
    config()->set('launchroom.daemon.system_images.caddy', 'docker.io/library/launchroom-caddy@sha256:'.str_repeat('a', 64));
    expect(resolve(RefreshNodeStates::class)->handle())->toBe(2);
    Queue::assertPushed(RenderServerNodeState::class, 4);
    expect(InstanceSettings::current()->node_state_config_hash)->toMatch('/^[0-9a-f]{64}$/');
});

test('a new dashboard version re-renders too; the scheduled command runs the refresh', function (): void {
    Queue::fake();
    Server::factory()->online()->create();
    $this->artisan('launchroom:node-states:refresh')->assertSuccessful();
    Queue::assertPushed(RenderServerNodeState::class, 1);

    releaseNodeStateRenderLocks();
    config()->set('launchroom.version', '9.9.9');
    $this->artisan('launchroom:node-states:refresh')->assertSuccessful();
    Queue::assertPushed(RenderServerNodeState::class, 2);
});
