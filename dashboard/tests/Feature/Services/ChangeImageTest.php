<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Environments\DiscardChanges;
use App\Data\Audit\AuditActor;
use App\Data\Services\ImageSource;
use App\Data\Specs\ChangeItem;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Team;
use App\Queries\CurrentEnvironmentSpec;
use App\Support\Changes\SpecDiff;
use Tests\Support\Projects;

/*
| Changing the image of an image service (docs/04 §1): the source is
| project-wide, validated like every image reference and staged until each
| environment deploys; the deploy runs the new image.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    $this->team = Team::factory()->create();
    $this->developer = memberOf($this->team, TeamRole::Developer);
    [$this->server] = Projects::server($this->team);
    $this->project = Projects::create($this->team);
    $this->production = $this->project->production()->sole();
    $this->web = Projects::image($this->project, 'web');
    resolve(DeployEnvironment::class)->handle($this->production, null, AuditActor::system());
    Projects::report($this->server);
    Projects::report($this->server);
    $this->url = route('projects.services.image', [$this->project, $this->production, $this->web]);
    $this->digest = 'ghcr.io/acme/web@sha256:'.str_repeat('b', 64);
});

test('the image is changed for the next deploy', function (): void {
    $before = $this->web->source->reference;

    $this->actingAs($this->developer)->patch($this->url, ['image' => " {$this->digest} "])->assertRedirect()->assertSessionHasNoErrors();

    $source = $this->web->refresh()->source;
    expect($source)->toBeInstanceOf(ImageSource::class)
        ->and($source->reference)->toBe($this->digest)
        ->and(AuditEvent::query()->where('action', AuditAction::ServiceImageChanged->value)->sole()->meta)->toEqual(['from' => $before, 'to' => $this->digest]);

    $production = $this->production->refresh()->load('lastAppliedRevision');
    $items = SpecDiff::between($production->lastAppliedRevision?->spec, resolve(CurrentEnvironmentSpec::class)->get($production))->items;
    expect(array_map(static fn (ChangeItem $item): string => $item->path, $items))->toContain("services.{$this->web->public_id}.source");

    // The running deployment keeps its image until the deploy.
    $active = Deployment::query()->whereKey($this->web->instances()->sole()->active_deployment_id)->sole();
    expect($active->image)->toContain('/docker.io/library/nginx@');

    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());
    $latest = Deployment::query()->where('service_instance_id', $this->web->instances()->sole()->id)->latest('id')->firstOrFail();

    expect($latest->image)->toBe('127.0.0.1:5000/mirror/ghcr.io/acme/web@sha256:'.str_repeat('b', 64));
});

test('discard puts the old image back', function (): void {
    $before = $this->web->source->reference;
    $this->actingAs($this->developer)->patch($this->url, ['image' => 'nginx:1.27'])->assertSessionHasNoErrors();

    resolve(DiscardChanges::class)->handle($this->production->refresh(), null, AuditActor::system());

    expect($this->web->refresh()->source->reference)->toBe($before);
});

test('image references are validated like everywhere else', function (): void {
    $this->actingAs(memberOf($this->team, TeamRole::Viewer))->patch($this->url, ['image' => 'nginx:1.27'])->assertForbidden();
    $this->actingAs($this->developer)->patch($this->url, ['image' => 'NOT VALID'])->assertSessionHasErrors('image');
    $this->actingAs($this->developer)->patch($this->url, ['image' => 'nginx@sha256:short'])->assertSessionHasErrors('image');
    $this->actingAs($this->developer)->patch($this->url, ['image' => 'quay.io/acme/web:1'])
        ->assertSessionHasErrors(['image' => 'Images from quay.io can’t be pulled yet: launchroom mirrors Docker Hub and GitHub Container Registry.']);

    expect(AuditEvent::query()->where('action', AuditAction::ServiceImageChanged->value)->exists())->toBeFalse();
});

test('only image services change their image', function (): void {
    $database = Projects::postgres($this->project);

    $this->actingAs($this->developer)->patch(route('projects.services.image', [$this->project, $this->production, $database]), ['image' => 'nginx:1.27'])
        ->assertSessionHasErrors('image');
});

test('a service of another team is not reachable', function (): void {
    $stranger = memberOf(Team::factory()->create(), TeamRole::Owner);

    $this->actingAs($stranger)->patch($this->url, ['image' => 'nginx:1.27'])->assertNotFound();
});
