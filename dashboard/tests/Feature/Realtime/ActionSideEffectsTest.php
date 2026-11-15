<?php

declare(strict_types=1);

use App\Actions\Domains\AddDomain;
use App\Data\Audit\AuditActor;
use App\Enums\DeploymentStatus;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Events\CanvasChanged;
use App\Events\NodeStateInvalidated;
use App\Jobs\Domains\VerifyDomainJob;
use App\Jobs\Security\EvaluateSecurityFindingsJob;
use App\Models\Server;
use App\Models\ServiceInstance;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;
use Tests\Support\SpyBroadcaster;

/*
 * Side effects belong to the Action that made the change (docs/10 §2.1), so
 * every caller gets them: web and API, blueprints, config-as-code, console.
 */

beforeEach(fn () => Projects::useTestKek());

function sideEffectsInstance(DeploymentUi $world): ServiceInstance
{
    return ServiceInstance::query()->where('service_id', $world->web->id)->where('environment_id', $world->production->id)->sole();
}

function assertCanvasNudged(DeploymentUi $world): void
{
    Event::assertDispatched(CanvasChanged::class, static fn (CanvasChanged $event): bool => $event->environmentId === $world->production->public_id
        && $event->serviceId === $world->web->public_id);
}

test('web stop, start, restart and deploy tell the canvas, like the API does (bug 9)', function (): void {
    $world = DeploymentUi::make();
    $member = $world->member(TeamRole::Developer);

    Event::fake([CanvasChanged::class]);
    $this->actingAs($member)->post(route('services.lifecycle.stop', $world->serviceArgs()))->assertRedirect();
    assertCanvasNudged($world);

    Event::fake([CanvasChanged::class]);
    $this->actingAs($member)->post(route('services.lifecycle.start', $world->serviceArgs()))->assertRedirect();
    assertCanvasNudged($world);

    Projects::report($world->server);
    Projects::report($world->server);
    expect($world->latest($world->web)->status)->toBe(DeploymentStatus::Active);

    Event::fake([CanvasChanged::class]);
    $this->actingAs($member)->post(route('services.lifecycle.restart', $world->serviceArgs()))->assertRedirect();
    assertCanvasNudged($world);

    Projects::report($world->server);
    Projects::report($world->server);

    Event::fake([CanvasChanged::class]);
    $this->actingAs($member)->post(route('services.lifecycle.deploy', $world->serviceArgs()))->assertRedirect();
    assertCanvasNudged($world);
});

test('a deployment status change reaches the canvas once (bug 9)', function (): void {
    $world = DeploymentUi::make();
    $spy = SpyBroadcaster::install();
    $active = $world->latest($world->web);

    $active->transitionTo(DeploymentStatus::Superseded);

    $canvas = array_values(array_filter($spy->sent, static fn (array $sent): bool => $sent['event'] === 'canvas.changed'));
    expect($canvas)->toHaveCount(1)
        ->and($canvas[0]['channels'])->toBe(['private-environments.'.$world->production->public_id]);
});

test('a canvas change inside a transaction goes out on commit, not on rollback', function (): void {
    $world = DeploymentUi::make();
    Event::fake([CanvasChanged::class]);

    try {
        DB::transaction(function () use ($world): void {
            CanvasChanged::forInstance(sideEffectsInstance($world));

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(CanvasChanged::class);

    DB::transaction(fn () => CanvasChanged::forInstance(sideEffectsInstance($world)));
    assertCanvasNudged($world);
});

test('a domain added outside the domain controllers is verified at once and re-evaluates findings (bug 10)', function (): void {
    $world = DeploymentUi::make();
    Bus::fake([VerifyDomainJob::class, EvaluateSecurityFindingsJob::class]);
    Event::fake([CanvasChanged::class]);

    // Blueprints and config-as-code call the action directly.
    $domain = resolve(AddDomain::class)->handle(sideEffectsInstance($world), 'shop.example.com', AuditActor::system());

    Bus::assertDispatched(VerifyDomainJob::class, static fn (VerifyDomainJob $job): bool => $job->domainId === $domain->id);
    Bus::assertDispatched(EvaluateSecurityFindingsJob::class, static fn (EvaluateSecurityFindingsJob $job): bool => $job->teamId === $world->team->id);
    assertCanvasNudged($world);
});

test('routes re-render every approved edge of the team, offline ones too, and nothing else', function (): void {
    $world = DeploymentUi::make();
    $offline = Server::factory()->create(['team_id' => $world->team->id, 'roles' => [ServerRole::Edge], 'status' => ServerStatus::Offline]);
    $pending = Server::factory()->create(['team_id' => $world->team->id, 'roles' => [ServerRole::Edge], 'status' => ServerStatus::PendingApproval]);
    $runtime = Server::factory()->online()->create(['team_id' => $world->team->id, 'roles' => [ServerRole::Runtime]]);
    Event::fake([NodeStateInvalidated::class]);

    NodeStateInvalidated::edges($world->team->id, [$runtime->id]);
    NodeStateInvalidated::servers([]);

    Event::assertDispatchedTimes(NodeStateInvalidated::class, 1);
    Event::assertDispatched(NodeStateInvalidated::class, static function (NodeStateInvalidated $event) use ($world, $offline, $pending, $runtime): bool {
        $ids = $event->serverIds;
        sort($ids);
        $expected = [$world->server->id, $offline->id, $runtime->id];
        sort($expected);

        return $ids === $expected && ! in_array($pending->id, $ids, true);
    });
});
