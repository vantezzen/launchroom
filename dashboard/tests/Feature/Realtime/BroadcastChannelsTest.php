<?php

declare(strict_types=1);

use App\Actions\Security\EvaluateSecurityFindings;
use App\Broadcasting\BroadcastEvent;
use App\Broadcasting\Channel;
use App\Broadcasting\ServiceActivityTopic;
use App\Enums\Backups\BackupReason;
use App\Enums\Backups\BackupRunStatus;
use App\Enums\Backups\BackupSubject;
use App\Enums\TeamRole;
use App\Events\Broadcasts\SecurityChanged;
use App\Events\Broadcasts\ServiceActivityChanged;
use App\Models\Backups\BackupRun;
use App\Models\ServiceInstance;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Tests\Support\Backups;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

test('the security channel authorizes the team’s members only', function (): void {
    $world = DeploymentUi::make(deploy: false);
    $outsider = DeploymentUi::make(deploy: false)->member(TeamRole::Owner);
    $viewer = $world->member(TeamRole::Viewer);
    $security = Broadcast::driver()->getChannels()[Channel::TeamSecurity->pattern()];

    expect($security($viewer, $world->team->public_id))->toBeTrue()
        ->and($security($outsider, $world->team->public_id))->toBeFalse()
        ->and($security($viewer, 'team_nope'))->toBeFalse();
});

test('only session wire names parse as sessions', function (): void {
    $server = 'srv_01JABCDEFGHJKMNPQRSTVWXYZ0';
    $session = 'ses_01JABCDEFGHJKMNPQRSTVWXYZ0';

    expect(Channel::Daemon->wire($server))->toBe("private-daemon.{$server}")
        ->and(Channel::sessionFromWire("private-session.{$session}"))->toBe($session)
        ->and(Channel::sessionFromWire("private-daemon.{$server}"))->toBeNull()
        ->and(Channel::sessionFromWire("private-session.{$server}"))->toBeNull()
        ->and(Channel::sessionFromWire("private-session.{$session}x"))->toBeNull()
        ->and(Channel::sessionFromWire('private-environments.env_01JABCDEFGHJKMNPQRSTVWXYZ0'))->toBeNull();
});

test('a backup run changing state nudges the service’s environment after commit', function (): void {
    Backups::fakeEnvironment();
    $world = DeploymentUi::make();
    $instance = ServiceInstance::query()->where('service_id', $world->postgres->id)->where('environment_id', $world->production->id)->sole();
    $destination = Backups::destination($world->team, $world->member(TeamRole::Owner), makeDefault: true);
    $run = BackupRun::query()->create([
        'team_id' => $world->team->id, 'destination_id' => $destination->id, 'subject' => BackupSubject::Database,
        'service_instance_id' => $instance->id, 'reason' => BackupReason::Manual,
    ]);
    Event::fake([ServiceActivityChanged::class]);

    $run->transitionTo(BackupRunStatus::Running);

    Event::assertDispatched(ServiceActivityChanged::class, fn (ServiceActivityChanged $event): bool => $event->topic === ServiceActivityTopic::Backups
        && $event->broadcastAs() === BroadcastEvent::ServiceActivity->value
        && $event->broadcastWith() === ['serviceId' => $world->postgres->public_id, 'topic' => 'backups']
        && array_map(fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn()) === ["private-environments.{$world->production->public_id}"]);
});

test('re-evaluating findings nudges the team’s security channel', function (): void {
    $world = DeploymentUi::make(deploy: false);
    Event::fake([SecurityChanged::class]);

    resolve(EvaluateSecurityFindings::class)->handle($world->team);

    Event::assertDispatched(SecurityChanged::class, fn (SecurityChanged $event): bool => $event->teamId === $world->team->public_id
        && array_map(fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn()) === ["private-security.{$world->team->public_id}"]);
});
