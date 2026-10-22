<?php

declare(strict_types=1);

use App\Actions\Crypto\RotateKek;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\Dockerfiles\AiProviderSettings;
use App\Models\Team;
use App\Models\User;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\SecretKey;
use App\Services\Dockerfiles\AiProviderSecrets;
use Tests\Support\Projects;

const DS_KEY = 'sk-ant-api03-settings-key-0123456789abcdefghijklmnop';

beforeEach(function (): void {
    Projects::useTestKek();
    config(['launchroom.dockerfile_generation.model' => 'anthropic']);
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->owner->forceFill(['is_instance_owner' => true])->save();
    $this->admin = memberOf($this->team, TeamRole::Admin);
    $this->developer = memberOf($this->team, TeamRole::Developer);
});

test('off by default; the page never shows the key', function (): void {
    expect(AiProviderSettings::current()->enabled)->toBeFalse()
        ->and($this->team->settings->generateDockerfiles)->toBeFalse();

    $this->actingAs($this->owner)->withSession(passwordConfirmed())->put(route('dockerfile-generation.update'), ['apiKey' => DS_KEY, 'enabled' => true, 'model' => 'claude-sonnet-5'])->assertRedirect();

    $settings = AiProviderSettings::current();
    expect($settings->enabled)->toBeTrue()
        ->and($settings->model)->toBe('claude-sonnet-5')
        ->and($settings->api_key_last4)->toBe('mnop')
        ->and($settings->api_key_ciphertext)->not->toContain(DS_KEY)
        ->and(json_encode($settings->toArray()))->not->toContain('ciphertext')
        ->and(resolve(AiProviderSecrets::class)->get($settings))->toBe(DS_KEY);

    $event = AuditEvent::query()->where('action', 'ai_provider.updated')->sole();
    expect(json_encode($event->meta))->not->toContain(DS_KEY);

    $page = $this->actingAs($this->owner)->get(route('dockerfile-generation.edit'))->assertOk();
    expect($page->getContent())->not->toContain(DS_KEY);
    $page->assertInertia(fn ($inertia) => $inertia
        ->component('settings/dockerfile-generation')
        ->where('provider.hasKey', true)
        ->where('provider.keyLast4', 'mnop')
        ->where('provider.model', 'claude-sonnet-5'));
});

test('only instance owners change the provider; turning it on needs a key', function (): void {
    foreach ([$this->admin, $this->developer] as $user) {
        /** @var User $user */
        $this->actingAs($user)->withSession(passwordConfirmed())->put(route('dockerfile-generation.update'), ['enabled' => true])->assertForbidden();
    }

    $this->actingAs($this->owner)->withSession(passwordConfirmed())->put(route('dockerfile-generation.update'), ['enabled' => true])->assertSessionHasErrors('apiKey');
    $this->actingAs($this->owner)->withSession(passwordConfirmed())->put(route('dockerfile-generation.update'), ['model' => 'gpt-5'])->assertSessionHasErrors('model');
});

test('an instance owner can set the provider without confirming again', function (): void {
    $this->actingAs($this->owner)->put(route('dockerfile-generation.update'), ['apiKey' => DS_KEY])->assertRedirect()->assertSessionHasNoErrors();
    expect(AiProviderSettings::current()->hasKey())->toBeTrue();
});

test('team owners switch the team; admins and developers can’t', function (): void {
    $this->actingAs($this->developer)->put(route('dockerfile-generation.team'), ['enabled' => true])->assertForbidden();
    $this->actingAs($this->admin)->put(route('dockerfile-generation.team'), ['enabled' => true])->assertForbidden();
    $this->actingAs($this->owner)->put(route('dockerfile-generation.team'), ['enabled' => true])->assertRedirect();

    expect($this->team->refresh()->settings->generateDockerfiles)->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'team.dockerfile_generation_changed')->exists())->toBeTrue();
});

test('the key is re-wrapped by KEK rotation', function (): void {
    $settings = AiProviderSettings::current();
    resolve(AiProviderSecrets::class)->put($settings, DS_KEY);
    $settings->save();

    $next = new Envelope(SecretKey::generate());
    $counts = resolve(RotateKek::class)->handle(resolve(Envelope::class), $next);

    expect($counts['rewrapped'])->toBeGreaterThanOrEqual(1)
        ->and(new AiProviderSecrets($next)->get(AiProviderSettings::current()))->toBe(DS_KEY);
});
