<?php

declare(strict_types=1);

use App\Actions\Environments\CreateEnvironment;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CreateEnvironmentData;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

test('palette services carry the status, live address and logo of production', function (): void {
    $world = DeploymentUi::make();
    $world->variable('STRIPE_SECRET', 'sk_live_never_shown', VariableType::Secret);
    $user = $world->member(TeamRole::Viewer);

    $data = $this->actingAs($user)->getJson(route('palette.index'))->assertOk()->json();
    $services = collect($data['services'])->keyBy('label');
    $domain = $world->web->instances()->first()?->domains()->first()?->hostname;

    expect($services['web']['environmentId'])->toBe($world->production->public_id)
        ->and($services['web']['nodeStatus']['state'])->toBe('active')
        ->and($services['web']['nodeStatus']['label'])->toBe('Live')
        ->and($services['web']['domain'])->toBe($domain)
        ->and($services['web']['serviceKind'])->toBe('image')
        ->and($services['postgres']['serviceKind'])->toBe('database')
        ->and($services['postgres']['logo'])->not->toBeNull()
        ->and($data['projects'][0]['nodeStatus']['state'])->toBe('active')
        // Viewers can't open the Data tab or deploy.
        ->and($data['canData'])->toBeFalse()
        ->and($data['canDeploy'])->toBeFalse()
        // Variable values never reach the palette.
        ->and(json_encode($data))->not->toContain('sk_live_never_shown');

    expect($this->actingAs($world->member(TeamRole::Owner))->getJson(route('palette.index'))->json('canData'))->toBeTrue();
});

test('the open environment reports its own statuses and lists only its services', function (): void {
    $world = DeploymentUi::make();
    $staging = resolve(CreateEnvironment::class)->handle($world->project, new CreateEnvironmentData('staging'), null, AuditActor::system());
    $user = $world->member(TeamRole::Viewer);

    $data = $this->actingAs($user)
        ->getJson(route('palette.index', ['environment' => $staging->public_id]))
        ->assertOk()
        ->json();

    $web = collect($data['services'])->firstWhere('label', 'web');

    expect($web['environmentId'])->toBe($staging->public_id)
        ->and($web['nodeStatus']['label'])->toBe('Not deployed')
        ->and($data['projects'][0]['environmentId'])->toBe($staging->public_id);

    // Another team's environment is ignored: production again.
    $other = DeploymentUi::make();
    $data = $this->actingAs($user)
        ->getJson(route('palette.index', ['environment' => $other->production->public_id]))
        ->assertOk()
        ->json();

    expect(collect($data['services'])->firstWhere('label', 'web')['environmentId'])->toBe($world->production->public_id)
        ->and(json_encode($data))->not->toContain($other->project->public_id);
});
