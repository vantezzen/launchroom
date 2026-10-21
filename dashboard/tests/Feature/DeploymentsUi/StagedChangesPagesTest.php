<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Models\EnvironmentRevision;
use App\Models\Variable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

beforeEach(fn () => Projects::useTestKek());

test('the pill summary counts staged changes with effects, never values', function (): void {
    $world = DeploymentUi::make();
    $world->variable('NEXT_PUBLIC_API_URL', 'https://api.example.test');
    $world->variable('API_TOKEN', 'tok_super_secret_value', VariableType::Secret);

    $summary = $this->actingAs($world->member(TeamRole::Viewer))
        ->getJson(route('staged-changes.summary', $world->envArgs()))
        ->assertOk()
        ->json();

    expect($summary['count'])->toBe(2)
        ->and($summary['canDeploy'])->toBeFalse()
        ->and($summary['groups'][0]['name'])->toBe('web')
        ->and($summary['groups'][0]['effect'])->toBe('restart')
        ->and(json_encode($summary))->not->toContain('tok_super_secret_value')
        ->and(json_encode($summary))->not->toContain('api.example.test');
});

test('the review page lists groups and broken references that block the deploy', function (): void {
    $world = DeploymentUi::make();
    $world->variable('BROKEN', '${{nowhere.KEY}}');

    $this->actingAs($world->member(TeamRole::Developer))
        ->get(route('staged-changes.show', $world->envArgs()))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('projects/changes')
            ->where('changes.count', 1)
            ->where('changes.canDeploy', true)
            ->where('changes.revision', 1)
            ->where('changes.groups.0.items.0.kind', 'added')
            ->where('changes.blockers.0', "web.BROKEN: \${{nowhere.KEY}} refers to a service that doesn't exist in this environment"));
});

test('deploy with a message lands on the project deployments', function (): void {
    $world = DeploymentUi::make();
    $world->variable('GREETING', 'hello');

    $this->actingAs($world->member(TeamRole::Viewer))->post(route('staged-changes.deploy', $world->envArgs()))->assertForbidden();

    $this->actingAs($world->member(TeamRole::Developer))
        ->post(route('staged-changes.deploy', $world->envArgs()), ['message' => 'Say hello'])
        ->assertRedirect(route('deployments.project', ['project' => $world->project->public_id, 'environment' => $world->production->public_id]));

    $revision = EnvironmentRevision::query()->where('environment_id', $world->production->id)->orderByDesc('number')->firstOrFail();
    expect($revision->number)->toBe(2)->and($revision->message)->toBe('Say hello');

    // Nothing left to deploy.
    $this->actingAs($world->member(TeamRole::Developer))
        ->from(route('staged-changes.show', $world->envArgs()))
        ->post(route('staged-changes.deploy', $world->envArgs()))
        ->assertSessionHasErrors('environment');
});

test('discard one item or everything', function (): void {
    $world = DeploymentUi::make();
    $one = $world->variable('ONE', '1');
    $world->variable('TWO', '2');
    $developer = $world->member(TeamRole::Developer);

    $this->actingAs($world->member(TeamRole::Viewer))->post(route('staged-changes.discard', $world->envArgs()))->assertForbidden();

    $this->actingAs($developer)
        ->post(route('staged-changes.discard', $world->envArgs()), ['paths' => ["services.{$world->web->public_id}.variables.{$one->public_id}"]])
        ->assertRedirect();

    expect(Variable::query()->where('key', 'ONE')->exists())->toBeFalse()
        ->and(Variable::query()->where('key', 'TWO')->exists())->toBeTrue();

    $this->actingAs($developer)->post(route('staged-changes.discard', $world->envArgs()), ['paths' => ['../etc']])->assertSessionHasErrors('paths.0');
    $this->actingAs($developer)->post(route('staged-changes.discard', $world->envArgs()))->assertRedirect();

    expect(Variable::query()->where('key', 'TWO')->exists())->toBeFalse();
});

test('the deploy dialog can ask the summary for blockers before submitting', function (): void {
    $world = DeploymentUi::make();
    $world->variable('BROKEN', '${{nowhere.KEY}}');
    $member = $world->member(TeamRole::Developer);

    // The pill's summary stays cheap: no reference resolution.
    expect($this->actingAs($member)->getJson(route('staged-changes.summary', $world->envArgs()))->json('blockers'))->toBe([]);

    $this->actingAs($member)
        ->getJson(route('staged-changes.summary', [...$world->envArgs(), 'blockers' => 1]))
        ->assertOk()
        ->assertJsonPath('blockers.0', "web.BROKEN: \${{nowhere.KEY}} refers to a service that doesn't exist in this environment")
        ->assertHeader('Cache-Control', 'no-store, private');
});
