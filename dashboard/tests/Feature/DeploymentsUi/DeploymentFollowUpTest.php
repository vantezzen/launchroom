<?php

declare(strict_types=1);

use App\Data\Instance\RealtimeConfigData;
use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Events\Broadcasts\DeploymentUpdated;
use App\Events\DeploymentStatusChanged;
use App\Listeners\BroadcastDeploymentStatus;
use App\Models\Membership;
use App\Queries\DeploymentDetailView;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Queue;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

test('the deployment page knows the newer and the older deployment of the same service', function (): void {
    $world = DeploymentUi::make();
    $first = $world->latest($world->web);
    $world->variable('FEATURE', 'on');
    $world->deploy();
    $second = $world->latest($world->web);

    expect($second->id)->not->toBe($first->id);

    $older = resolve(DeploymentDetailView::class)->get($first);
    $newer = resolve(DeploymentDetailView::class)->get($second);

    expect($older->newer?->id)->toBe($second->public_id)
        ->and($older->older)->toBeNull()
        ->and($newer->older?->id)->toBe($first->public_id)
        ->and($newer->newer)->toBeNull();
});

test('a member channel admits only the user of that membership', function (): void {
    $world = DeploymentUi::make(deploy: false);
    $owner = $world->member(TeamRole::Developer);
    $other = $world->member(TeamRole::Owner);
    $membership = Membership::query()->where('team_id', $world->team->id)->where('user_id', $owner->id)->sole();

    $channels = Broadcast::driver()->getChannels();
    $members = $channels['members.{member}'];

    expect($members($owner, $membership->public_id))->toBeTrue()
        ->and($members($other, $membership->public_id))->toBeFalse()
        ->and($members($owner, 'mem_nope'))->toBeFalse()
        ->and($members($owner, $world->production->public_id))->toBeFalse();
});

test('the realtime prop names the signed-in member’s channel in the current team', function (): void {
    $world = DeploymentUi::make(deploy: false);
    $user = $world->member(TeamRole::Developer);
    $user->forceFill(['current_team_id' => $world->team->id])->save();
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key']);
    $membership = Membership::query()->where('team_id', $world->team->id)->where('user_id', $user->id)->sole();

    expect(RealtimeConfigData::current($user)?->member)->toBe($membership->public_id)
        ->and(RealtimeConfigData::current()?->member)->toBeNull();
});

test('status changes also go to the member who started the deployment, with names for the toast', function (): void {
    $world = DeploymentUi::make();
    $user = $world->member(TeamRole::Developer);
    $deployment = $world->latest($world->web);
    $deployment->forceFill(['actor_id' => $user->id])->save();
    $membership = Membership::query()->where('team_id', $world->team->id)->where('user_id', $user->id)->sole();
    Queue::fake();

    resolve(BroadcastDeploymentStatus::class)->handle(new DeploymentStatusChanged($deployment->id, DeploymentStatus::Queued, DeploymentStatus::Building));

    Queue::assertPushed(BroadcastEvent::class, function (BroadcastEvent $job) use ($world, $deployment, $membership): bool {
        if (! $job->event instanceof DeploymentUpdated) {
            return false;
        }

        $payload = $job->event->broadcastWith();

        return array_map(fn (PrivateChannel $channel): string => $channel->name, $job->event->broadcastOn()) === [
            "private-environments.{$world->production->public_id}",
            "private-deployments.{$deployment->public_id}",
            "private-members.{$membership->public_id}",
        ]
            && $payload['projectId'] === $world->project->public_id
            && $payload['serviceName'] === 'web'
            && $payload['status'] === 'building';
    });
});
