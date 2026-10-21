<?php

declare(strict_types=1);

use App\Enums\Previews\ForkPolicy;
use App\Enums\PreviewStrategy;
use App\Enums\TeamRole;
use App\Models\Previews\PreviewSettings;
use App\Models\ServiceInstance;
use App\Models\Team;
use Tests\Support\PreviewFlow;

beforeEach(function (): void {
    PreviewFlow::setUp();
    config(['inertia.ssr.enabled' => false]);
    [$this->team, $this->server, , $this->project, $this->production, $this->postgres, $this->web] = PreviewFlow::project();
});

function ppSettings(array $overrides = []): array
{
    return [
        'enabled' => true,
        'baseEnvironmentId' => null,
        'targetBranches' => 'main, release/*',
        'requiredLabel' => '',
        'maxConcurrent' => 2,
        'expiryDays' => 5,
        'forkPolicy' => 'approval',
        'protection' => true,
        'allowProductionClone' => false,
        ...$overrides,
    ];
}

test('settings → previews shows the settings and the databases with their strategy', function (): void {
    $viewer = memberOf($this->team, TeamRole::Viewer);

    $this->actingAs($viewer)->get(route('projects.settings', ['project' => $this->project, 'section' => 'previews']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('projects/settings')
            ->where('section', 'previews')
            ->where('previewSettings.enabled', true)
            ->where('previewSettings.effectiveBaseId', $this->production->public_id)
            ->where('previewSettings.maxConcurrent', 3)
            ->where('previewSettings.forkPolicy', 'off')
            ->where('previewSettings.protection', true)
            ->where('previewSettings.canManage', false)
            ->where('previewSettings.cloneLimitMb', 1024)
            ->where('previewSettings.services.0.name', 'postgres')
            ->where('previewSettings.services.0.strategy', 'new_empty')
            ->missing('previewSettings.bypassNonce'));
});

test('developers save preview settings; viewers can’t; production clones need an admin', function (): void {
    $viewer = memberOf($this->team, TeamRole::Viewer);
    $developer = memberOf($this->team, TeamRole::Developer);
    $admin = memberOf($this->team, TeamRole::Admin);

    $this->actingAs($viewer)->put(route('projects.previews.settings', $this->project), ppSettings())->assertForbidden();
    $this->actingAs($developer)->put(route('projects.previews.settings', $this->project), ppSettings(['maxConcurrent' => 0]))->assertSessionHasErrors('maxConcurrent');
    $this->actingAs($developer)->put(route('projects.previews.settings', $this->project), ppSettings(['targetBranches' => 'main; rm -rf']))->assertSessionHasErrors('targetBranches');
    $this->actingAs($developer)->put(route('projects.previews.settings', $this->project), ppSettings())->assertRedirect()->assertSessionHasNoErrors();

    $settings = PreviewSettings::forProject($this->project);
    expect($settings->max_concurrent)->toBe(2)
        ->and($settings->fork_policy)->toBe(ForkPolicy::Approval)
        ->and($settings->target_branches)->toBe('main, release/*')
        ->and($settings->required_label)->toBeNull()
        ->and($settings->expiry_days)->toBe(5);

    $this->actingAs($developer)->put(route('projects.previews.settings', $this->project), ppSettings(['allowProductionClone' => true]))->assertSessionHasErrors('allowProductionClone');
    $this->actingAs($admin)->put(route('projects.previews.settings', $this->project), ppSettings(['allowProductionClone' => true]))->assertSessionHasNoErrors();
    expect($settings->refresh()->allow_production_clone)->toBeTrue();

    // Another team's environment can't be the base.
    [, , , $other] = PreviewFlow::project();
    $this->actingAs($admin)->put(route('projects.previews.settings', $this->project), ppSettings(['baseEnvironmentId' => $other->production()->sole()->public_id]))->assertSessionHasErrors('baseEnvironmentId');
    $this->actingAs(memberOf(Team::factory()->create(), TeamRole::Owner))->put(route('projects.previews.settings', $this->project), ppSettings())->assertNotFound();
});

test('the "In previews" strategy is set on the base environment’s database', function (): void {
    $developer = memberOf($this->team, TeamRole::Developer);

    // Sharing production's database needs the owner/admin production toggle.
    $this->actingAs($developer)->put(route('projects.previews.strategy', [$this->project, $this->postgres]), ['strategy' => 'share'])->assertSessionHasErrors('strategy');
    PreviewSettings::forProject($this->project)->forceFill(['allow_production_clone' => true])->save();
    $this->actingAs($developer)->put(route('projects.previews.strategy', [$this->project, $this->postgres]), ['strategy' => 'share'])->assertRedirect()->assertSessionHasNoErrors();
    expect(ServiceInstance::query()->where('service_id', $this->postgres->id)->where('environment_id', $this->production->id)->sole()->preview_strategy)->toBe(PreviewStrategy::Share);

    $this->actingAs($developer)->put(route('projects.previews.strategy', [$this->project, $this->web]), ['strategy' => 'share'])->assertSessionHasErrors('strategy');
    $this->actingAs(memberOf($this->team, TeamRole::Viewer))->put(route('projects.previews.strategy', [$this->project, $this->postgres]), ['strategy' => 'new_empty'])->assertForbidden();
});

test('the previews page lists pull requests with status, URLs and expiry; the canvas of a preview shows its PR and database badges', function (): void {
    ServiceInstance::query()->where('service_id', $this->postgres->id)->where('environment_id', $this->production->id)->update(['preview_strategy' => 'share']);
    PreviewSettings::forProject($this->project)->forceFill(['allow_production_clone' => true])->save();
    $preview = PreviewFlow::event($this->project);
    PreviewFlow::run($this->server);
    $viewer = memberOf($this->team, TeamRole::Viewer);

    $this->actingAs($viewer)->get(route('projects.previews', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('projects/previews')
            ->has('previews', 1)
            ->where('previews.0.number', 7)
            ->where('previews.0.status', 'running')
            ->where('previews.0.health', 'live')
            ->where('previews.0.urls.0.url', 'https://web-pr-7.shop.localhost')
            ->where('previews.0.databases.0.strategy', 'share')
            ->where('previews.0.databases.0.source', 'production')
            ->where('canShare', false)
            ->where('canApprove', false)
            ->has('environments', 2));

    $this->actingAs($viewer)->get(route('projects.canvas', [$this->project, $preview->refresh()->environment]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('preview.number', 7)
            ->where('preview.edgeBadges.0.serviceId', $this->postgres->public_id)
            ->where('preview.edgeBadges.0.tone', 'danger')
            ->where('preview.edgeBadges.0.label', 'shared with production'));

    $this->actingAs(memberOf(Team::factory()->create(), TeamRole::Owner))->get(route('projects.previews', $this->project))->assertNotFound();
});
