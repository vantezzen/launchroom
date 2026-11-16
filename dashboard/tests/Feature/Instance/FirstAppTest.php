<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Data\Audit\AuditActor;
use App\Enums\TeamRole;
use App\Models\Deployment;
use App\Models\InstanceSettings;
use App\Models\User;
use App\Queries\FirstApp;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

function firstAppOwner(DeploymentUi $world): User
{
    $owner = $world->member(TeamRole::Owner);
    $owner->forceFill(['is_instance_owner' => true])->save();

    return $owner;
}

test('the first-app step waits for a live app, showing the first deploy while it runs', function (): void {
    $world = DeploymentUi::make(deploy: false);
    $owner = firstAppOwner($world);

    $this->actingAs($owner)->get(route('onboarding.show'))
        ->assertInertia(fn ($page) => $page->where('onboarding.firstApp', null)->where('onboarding.steps.3.status', 'pending'));

    resolve(DeployEnvironment::class)->handle($world->production, null, AuditActor::system());

    $this->actingAs($owner)->get(route('onboarding.show'))
        ->assertInertia(fn ($page) => $page
            ->where('onboarding.steps.3.status', 'pending')
            ->where('onboarding.firstApp.state', 'deploying')
            ->where('onboarding.firstApp.serviceName', 'web')
            ->where('onboarding.firstApp.projectId', $world->project->public_id)
            ->where('onboarding.firstApp.deploymentId', $world->latest($world->web)->public_id)
            // An image service: no Build row.
            ->where('onboarding.firstApp.steps', fn ($steps): bool => array_column($steps->all(), 'label') === ['Start', 'Health check', 'Route']));

    for ($i = 0; $i < 4; $i++) {
        Projects::report($world->server);
    }

    $hostname = $world->web->instances()->sole()->domains()->firstOrFail()->hostname;

    $this->actingAs($owner)->get(route('onboarding.show'))
        ->assertInertia(fn ($page) => $page
            ->where('onboarding.steps.3.status', 'done')
            ->where('onboarding.firstApp.state', 'live')
            ->where('onboarding.firstApp.hostname', $hostname)
            ->where('onboarding.firstApp.steps', []));
});

test('the first-live card shows once, for instance owners, soon after the app went live', function (): void {
    $world = DeploymentUi::make();
    $owner = firstAppOwner($world);

    $this->actingAs($world->member(TeamRole::Admin))->getJson(route('onboarding.first-app'))->assertForbidden();
    $this->actingAs($world->member(TeamRole::Admin))->post(route('onboarding.first-app.dismiss'))->assertForbidden();

    $this->actingAs($owner)->getJson(route('onboarding.first-app'))
        ->assertOk()
        ->assertJsonPath('firstApp.state', 'live')
        ->assertJsonPath('firstApp.serviceId', $world->web->public_id)
        ->assertJsonPath('firstApp.environmentId', $world->production->public_id);

    $this->actingAs($owner)->post(route('onboarding.first-app.dismiss'))->assertRedirect();

    expect(InstanceSettings::current()->onboarding?->firstAppSeenAt)->toBeString();
    $this->actingAs($owner)->getJson(route('onboarding.first-app'))->assertOk()->assertJsonPath('firstApp', null);
});

test('an app that went live long ago gets no card', function (): void {
    $world = DeploymentUi::make();
    Deployment::query()->update(['finished_at' => now()->subDays(3)]);

    expect(resolve(FirstApp::class)->card($world->team, seen: false))->toBeNull()
        ->and(resolve(FirstApp::class)->for($world->team)?->state)->toBe('live');
});
