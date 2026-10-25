<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Http\Controllers\Git\GitSettingsController;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Tests\Support\GitHubFake;

/*
| The GitHub round trips return where they started (docs/08): an
| allow-listed key (`git`, `onboarding`), never a URL; onboarding only for
| instance owners.
*/

beforeEach(function (): void {
    GitHubFake::setUp();
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->owner->forceFill(['is_instance_owner' => true])->save();
    $this->admin = memberOf($this->team, TeamRole::Admin);
});

function returnToConversion(): array
{
    return [
        'id' => 4242,
        'slug' => 'launchroom-abc',
        'name' => 'launchroom-abc',
        'client_id' => 'Iv1.abc',
        'client_secret' => 'client-secret-plain',
        'webhook_secret' => 'webhook-secret-plain',
        'pem' => GitHubFake::privateKey(),
        'html_url' => 'https://github.com/apps/launchroom-abc',
        'owner' => ['login' => 'octo-org', 'type' => 'Organization'],
    ];
}

test('connecting GitHub from onboarding comes back to onboarding', function (): void {
    Http::fake(['https://api.github.com/app-manifests/abc123/conversions' => Http::response(returnToConversion(), 201)]);

    $this->actingAs($this->owner)
        ->post(route('git.github.manifest.store'), ['returnTo' => 'onboarding'])
        ->assertRedirect(route('onboarding.show'));

    $redirect = session(GitSettingsController::MANIFEST_FLASH_KEY);
    parse_str((string) parse_url($redirect['action'], PHP_URL_QUERY), $query);

    // Onboarding posts the manifest to GitHub itself (inline step).
    $this->get(route('onboarding.show'))->assertInertia(fn ($page) => $page
        ->component('onboarding')
        ->where('githubManifest.action', fn (string $action): bool => str_starts_with($action, 'https://github.com/settings/apps/new?state=')));

    $this->get(route('git.github.manifest.callback', ['code' => 'abc123', 'state' => $query['state']]))
        ->assertRedirect(route('onboarding.show'))
        ->assertSessionHasNoErrors();

    $this->get(route('onboarding.show'))->assertInertia(fn ($page) => $page
        ->where('githubManifest', null)
        ->where('onboarding.githubAppName', 'launchroom-abc')
        ->where('onboarding.githubInstallUrl', 'https://github.com/apps/launchroom-abc/installations/new?state=onboarding'));
});

test('unknown or external return targets fall back to Settings → Git', function (string $returnTo): void {
    $this->actingAs($this->owner)
        ->post(route('git.github.manifest.store'), ['returnTo' => $returnTo])
        ->assertRedirect(route('git.edit'));
})->with(['https://evil.example/', '//evil.example', '/onboarding', 'projects', '']);

test('only instance owners return to onboarding', function (): void {
    $this->actingAs($this->admin)
        ->post(route('git.github.manifest.store'), ['returnTo' => 'onboarding'])
        ->assertRedirect(route('git.edit'));

    $this->actingAs($this->admin)
        ->get(route('git.github.setup', ['setup_action' => 'request', 'state' => 'onboarding']))
        ->assertRedirect(route('git.edit'));
});

test('a failed callback still returns to where the flow started', function (): void {
    $this->actingAs($this->owner)
        ->post(route('git.github.manifest.store'), ['returnTo' => 'onboarding']);

    $this->get(route('git.github.manifest.callback', ['code' => 'abc123', 'state' => 'lr_ghm_forged']))
        ->assertRedirect(route('onboarding.show'))
        ->assertSessionHasErrors();
});

test('installing the app returns to the step that sent the user to GitHub', function (string $state, string $route): void {
    GitHubFake::app($this->team);

    $this->actingAs($this->owner)
        ->get(route('git.github.setup', ['setup_action' => 'request', 'state' => $state]))
        ->assertRedirect(route($route));

    // A malformed installation keeps the same return target.
    $this->actingAs($this->owner)
        ->get(route('git.github.setup', ['installation_id' => 'x', 'state' => $state]))
        ->assertRedirect(route($route))
        ->assertSessionHasErrors('github');
})->with([
    'onboarding' => ['onboarding', 'onboarding.show'],
    'settings' => ['git', 'git.edit'],
    'external' => ['https://evil.example', 'git.edit'],
    'none' => ['', 'git.edit'],
]);
