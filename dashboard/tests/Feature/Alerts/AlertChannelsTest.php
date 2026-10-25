<?php

declare(strict_types=1);

use App\Actions\Alerts\RaiseAlert;
use App\Actions\Crypto\RotateKek;
use App\Enums\Alerts\AlertChannelKind;
use App\Enums\Alerts\AlertDeliveryStatus;
use App\Enums\Alerts\AlertRule;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\Alerts\Alert;
use App\Models\Alerts\AlertChannel;
use App\Models\Alerts\AlertDelivery;
use App\Models\Alerts\AlertRuleSetting;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Notifications\AlertEmailNotification;
use App\Services\Alerts\AlertChannelSecrets;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\SecretKey;
use App\Support\Alerts\WebhookSignature;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Alerts;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    Alerts::fakeDns();
    config(['app.url' => 'https://dash.example.com']);
    Http::fake([
        'hooks.slack.com/*' => Http::response('ok'),
        'discord.com/*' => Http::response(null, 204),
        'hooks.example.com/*' => Http::response(['ok' => true]),
    ]);
    $this->team = Team::factory()->create(['name' => 'Acme']);
    $this->admin = memberOf($this->team, TeamRole::Admin);
});

test('admins add a Slack channel; the URL is encrypted and never rendered, only its hint', function (): void {
    $this->actingAs($this->admin)
        ->post(route('notifications.channels.store'), ['kind' => 'slack', 'secretUrl' => Alerts::SLACK])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $channel = AlertChannel::query()->where('team_id', $this->team->id)->sole();

    expect($channel->kind)->toBe(AlertChannelKind::Slack)
        ->and($channel->name)->toBe('Slack')
        ->and($channel->target_hint)->toBe('hooks.slack.com/…uvwx')
        ->and($channel->secret)->toStartWith('lr1:')
        ->and(json_encode($channel->toArray(), JSON_THROW_ON_ERROR))->not->toContain('lr1:')->not->toContain('abcdefghijklmnop')
        ->and(resolve(AlertChannelSecrets::class)->reveal($channel)->url)->toBe(Alerts::SLACK)
        ->and(print_r(resolve(AlertChannelSecrets::class)->reveal($channel), true))->not->toContain('hooks.slack.com');

    $page = $this->actingAs($this->admin)->get(route('notifications.edit'))->assertOk();
    expect($page->getContent())->not->toContain('abcdefghijklmnopqrstuvwx');
    $page->assertInertia(fn (AssertableInertia $p): AssertableInertia => $p->component('settings/notifications')
        ->where('channels.0.hint', 'hooks.slack.com/…uvwx')
        ->where('canManage', true)
        ->has('rules', count(AlertRule::cases())));

    $audit = AuditEvent::query()->where('action', AuditAction::AlertChannelCreated->value)->sole();
    expect(json_encode($audit->meta, JSON_THROW_ON_ERROR))->not->toContain('hooks.slack.com');
});

test('channel URLs must match their kind and the webhook URL must be https', function (string $kind, string $url): void {
    $this->actingAs($this->admin)
        ->post(route('notifications.channels.store'), ['kind' => $kind, 'secretUrl' => $url])
        ->assertSessionHasErrors('secretUrl');

    expect(AlertChannel::query()->count())->toBe(0);
})->with([
    'slack: another host' => ['slack', 'https://evil.example.com/services/T1/B2/c3'],
    'slack: http' => ['slack', 'http://hooks.slack.com/services/T1/B2/c3'],
    'discord: wrong path' => ['discord', 'https://discord.com/channels/1/2'],
    'webhook: http' => ['webhook', 'http://hooks.example.com/x'],
    'webhook: credentials' => ['webhook', 'https://user:pass@hooks.example.com/x'],
]);

test('email channels need valid addresses', function (): void {
    $this->actingAs($this->admin)
        ->post(route('notifications.channels.store'), ['kind' => 'email', 'recipients' => ['not-an-address']])
        ->assertSessionHasErrors();
    $this->actingAs($this->admin)
        ->post(route('notifications.channels.store'), ['kind' => 'email', 'recipients' => []])
        ->assertSessionHasErrors('recipients');

    $this->actingAs($this->admin)
        ->post(route('notifications.channels.store'), ['kind' => 'email', 'recipients' => ['Ops@Example.com', 'ops@example.com', 'mara@example.com']])
        ->assertSessionHasNoErrors();

    expect(AlertChannel::query()->sole()->recipients)->toBe(['ops@example.com', 'mara@example.com']);
});

test('a webhook gets a signing secret shown once, and its events are signed over the exact body', function (): void {
    $response = $this->actingAs($this->admin)
        ->post(route('notifications.channels.store'), ['kind' => 'webhook', 'secretUrl' => Alerts::WEBHOOK]);
    $flash = session('inertia.flash_data') ?? $response->getSession()?->get('inertia.flash_data');
    $channel = AlertChannel::query()->sole();
    $secret = resolve(AlertChannelSecrets::class)->reveal($channel)->signingSecret;

    expect($secret)->toStartWith('lrwh_')
        ->and(json_encode($flash))->toContain((string) $secret);

    // The page after the redirect shows it; after that, never again.
    expect($this->actingAs($this->admin)->get(route('notifications.edit'))->getContent())->toContain((string) $secret)
        ->and($this->actingAs($this->admin)->get(route('notifications.edit'))->getContent())->not->toContain((string) $secret);

    resolve(RaiseAlert::class)->handle(Alerts::signal($this->team, facts: ['Destination' => 'r2-eu']));

    Http::assertSent(function (Request $request) use ($secret): bool {
        if ($request->url() !== Alerts::WEBHOOK) {
            return false;
        }

        $payload = json_decode($request->body(), true);

        return WebhookSignature::verify((string) $secret, $request->header('X-Launchroom-Signature')[0] ?? '', $request->body(), now()->getTimestamp())
            && ! WebhookSignature::verify((string) $secret, $request->header('X-Launchroom-Signature')[0] ?? '', $request->body().' ', now()->getTimestamp())
            && ! WebhookSignature::verify('lrwh_other', $request->header('X-Launchroom-Signature')[0] ?? '', $request->body(), now()->getTimestamp())
            && $request->header('X-Launchroom-Event')[0] === 'alert.fired'
            && str_starts_with($request->header('X-Launchroom-Delivery')[0] ?? '', 'adl_')
            && $payload['type'] === 'alert.fired'
            && $payload['rule'] === 'backup_failed'
            && $payload['team'] === ['id' => $this->team->public_id, 'name' => 'Acme']
            && $payload['facts'] === ['Destination' => 'r2-eu']
            && str_starts_with($payload['url'], 'https://dash.example.com/');
    });

    // Rotating issues a new secret; the old one stops verifying.
    $this->actingAs($this->admin)->patch(route('notifications.channels.update', $channel), ['rotateSecret' => true])->assertSessionHasNoErrors();
    expect(resolve(AlertChannelSecrets::class)->reveal($channel->refresh())->signingSecret)->not->toBe($secret)
        ->and(resolve(AlertChannelSecrets::class)->reveal($channel)->url)->toBe(Alerts::WEBHOOK);
});

test('the webhook signature rejects stale timestamps and malformed headers', function (): void {
    $header = WebhookSignature::header('lrwh_s', 1_000_000, '{}');

    expect(WebhookSignature::verify('lrwh_s', $header, '{}', 1_000_100))->toBeTrue()
        ->and(WebhookSignature::verify('lrwh_s', $header, '{}', 1_000_400))->toBeFalse()
        ->and(WebhookSignature::verify('', $header, '{}', 1_000_000))->toBeFalse()
        ->and(WebhookSignature::verify('lrwh_s', 'v1=abc', '{}', 1_000_000))->toBeFalse();
});

test('Slack and Discord get their own formats, without mentions', function (): void {
    Alerts::channel($this->team, $this->admin, AlertChannelKind::Slack);
    Alerts::channel($this->team, $this->admin, AlertChannelKind::Discord);

    resolve(RaiseAlert::class)->handle(Alerts::signal($this->team, facts: ['Destination' => 'r2 <eu>']));

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return str_starts_with($request->url(), 'https://hooks.slack.com/')
            && $data['text'] === 'Backup of postgres failed'
            && $data['blocks'][0]['text']['text'] === ":warning: *Backup of postgres failed*\nThe bucket could not be reached. The last good backup is from 3 hours ago."
            && $data['blocks'][1]['fields'][0]['text'] === "*Destination*\nr2 &lt;eu&gt;"
            && end($data['blocks'])['elements'][0]['url'] === 'https://dash.example.com/activity';
    });
    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return str_starts_with($request->url(), 'https://discord.com/')
            && $data['allowed_mentions'] === ['parse' => []]
            && $data['embeds'][0]['title'] === 'Backup of postgres failed'
            && $data['embeds'][0]['color'] === 0xC4211B
            && $data['embeds'][0]['fields'][0] === ['name' => 'Destination', 'value' => 'r2 <eu>', 'inline' => true];
    });
});

test('email channels send one calm message per address', function (): void {
    Notification::fake();
    Alerts::channel($this->team, $this->admin, AlertChannelKind::Email, recipients: ['ops@example.com', 'mara@example.com']);

    resolve(RaiseAlert::class)->handle(Alerts::signal($this->team));

    Notification::assertSentOnDemandTimes(AlertEmailNotification::class, 2);
    Notification::assertSentOnDemand(AlertEmailNotification::class, function (AlertEmailNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool {
        $mail = $notification->toMail($notifiable);

        return $notifiable->routes['mail'] === 'ops@example.com' && $mail->subject === 'Backup of postgres failed' && $mail->actionText === 'Open in launchroom';
    });
});

test('send a test: success and failure are reported right away, without the URL', function (): void {
    $channel = Alerts::channel($this->team, $this->admin);

    $this->actingAs($this->admin)->post(route('notifications.channels.test', $channel))->assertRedirect();
    expect(AlertDelivery::query()->sole()->status)->toBe(AlertDeliveryStatus::Sent);
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'launchroom can reach you here'));

    // A webhook URL that resolves to a private address is refused (SSRF).
    $internal = Alerts::channel($this->team, $this->admin, AlertChannelKind::Webhook, url: 'https://internal.example.com/hook');
    $this->actingAs($this->admin)->post(route('notifications.channels.test', $internal))->assertRedirect();

    $failed = AlertDelivery::query()->where('channel_id', $internal->id)->sole();
    expect($failed->status)->toBe(AlertDeliveryStatus::Failed)
        ->and($failed->error)->toBe('This address is not allowed: it must be https and reachable on the public internet.')
        ->and($internal->refresh()->last_error)->toBe($failed->error);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'internal.example.com'));
});

test('pausing a channel stops its alerts; removing it deletes its history', function (): void {
    $channel = Alerts::channel($this->team, $this->admin);

    $this->actingAs($this->admin)->patch(route('notifications.channels.update', $channel), ['enabled' => false])->assertSessionHasNoErrors();
    resolve(RaiseAlert::class)->handle(Alerts::signal($this->team));
    Http::assertNothingSent();

    $this->actingAs($this->admin)->delete(route('notifications.channels.destroy', $channel))->assertRedirect();
    expect(AlertChannel::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditAction::AlertChannelDeleted->value)->count())->toBe(1);
});

test('rules save within their bounds; switching one off resolves its open alerts quietly', function (): void {
    Alerts::channel($this->team, $this->admin);
    resolve(RaiseAlert::class)->handle(Alerts::signal($this->team));

    $this->actingAs($this->admin)->put(route('notifications.rules.update'), ['rules' => [
        ['rule' => 'cpu_high', 'enabled' => true, 'threshold' => 101, 'duration' => 10],
    ]])->assertSessionHasErrors('rules.0.threshold');

    $this->actingAs($this->admin)->put(route('notifications.rules.update'), ['rules' => [
        ['rule' => 'cpu_high', 'enabled' => true, 'threshold' => 80, 'duration' => 5],
        ['rule' => 'backup_failed', 'enabled' => false],
    ]])->assertSessionHasNoErrors();

    expect(AlertRuleSetting::query()->where('rule', 'cpu_high')->sole()->threshold)->toBe(80.0)
        ->and(AlertRuleSetting::query()->where('rule', 'cpu_high')->sole()->duration_minutes)->toBe(5)
        ->and(Alert::query()->sole()->resolve_reason)->toBe('This alert was switched off.')
        ->and(AlertDelivery::query()->where('event', 'resolved')->count())->toBe(0);
});

test('only owners and admins manage channels; members see them; other teams get 404', function (TeamRole $role, bool $manage): void {
    $owner = memberOf($this->team, TeamRole::Owner);
    $channel = Alerts::channel($this->team, $owner);
    $user = memberOf($this->team, $role);

    $this->actingAs($user)->get(route('notifications.edit'))->assertOk()
        ->assertInertia(fn (AssertableInertia $p): AssertableInertia => $p->where('canManage', $manage)->where('channels.0.canManage', $manage));

    $status = $manage ? 302 : 403;
    $this->actingAs($user)->post(route('notifications.channels.store'), ['kind' => 'slack', 'secretUrl' => Alerts::SLACK])->assertStatus($status);
    $this->actingAs($user)->patch(route('notifications.channels.update', $channel), ['name' => 'Ops'])->assertStatus($status);
    $this->actingAs($user)->post(route('notifications.channels.test', $channel))->assertStatus($status);
    $this->actingAs($user)->put(route('notifications.rules.update'), ['rules' => [['rule' => 'cpu_high', 'enabled' => false]]])->assertStatus($status);
    $this->actingAs($user)->delete(route('notifications.channels.destroy', $channel))->assertStatus($status);

    // Another team's member: the channel doesn't exist for them.
    $stranger = memberOf(Team::factory()->create(), TeamRole::Owner);
    if (AlertChannel::query()->whereKey($channel->id)->exists()) {
        $this->actingAs($stranger)->patch(route('notifications.channels.update', $channel), ['name' => 'x'])->assertNotFound();
        $this->actingAs($stranger)->post(route('notifications.channels.test', $channel))->assertNotFound();
        $this->actingAs($stranger)->delete(route('notifications.channels.destroy', $channel))->assertNotFound();
    }
})->with([
    'owner' => [TeamRole::Owner, true],
    'admin' => [TeamRole::Admin, true],
    'developer' => [TeamRole::Developer, false],
    'viewer' => [TeamRole::Viewer, false],
]);

test('KEK rotation rewraps channel keys; email channels have none', function (): void {
    $slack = Alerts::channel($this->team, $this->admin);
    Alerts::channel($this->team, $this->admin, AlertChannelKind::Email);

    $current = resolve(Envelope::class);
    $next = new Envelope(SecretKey::generate());
    resolve(RotateKek::class)->handle($current, $next);
    app()->instance(Envelope::class, $next);
    app()->forgetInstance(AlertChannelSecrets::class);

    expect($slack->refresh()->kek_id)->toBe($next->kekId())
        ->and(resolve(AlertChannelSecrets::class)->reveal($slack)->url)->toBe(Alerts::SLACK);
});
