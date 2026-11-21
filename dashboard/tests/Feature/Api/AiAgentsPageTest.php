<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\ApiToken;
use Inertia\Testing\AssertableInertia;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

/*
 * Settings → AI agents (docs/16 §7.6, D112): setup steps for `launchroom
 * mcp`. It shows what the member's role allows and creates nothing.
 */

beforeEach(fn () => Projects::useTestKek());

test('every member sees the setup with what their role allows', function (TeamRole $role, bool $canRun): void {
    $world = DeploymentUi::make(false);

    $this->actingAs($world->member($role))
        ->get('/settings/ai-agents')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('settings/ai-agents')
            ->where('teamOptions.0.role', $role->value)
            ->where('teamOptions.0.abilities.7.value', 'run')
            ->where('teamOptions.0.abilities.7.available', $canRun)
            ->where('cliInstall', 'curl -fsSL https://github.com/vantezzen/launchroom/releases/latest/download/cli.sh | sh'));

    expect(ApiToken::query()->count())->toBe(0);
})->with([
    'owner' => [TeamRole::Owner, true],
    'developer' => [TeamRole::Developer, false],
    'viewer' => [TeamRole::Viewer, false],
]);

test('guests are sent to sign in', function (): void {
    $this->get('/settings/ai-agents')->assertRedirect('/login');
});
