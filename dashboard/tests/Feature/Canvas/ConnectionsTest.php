<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Connection;
use App\Models\Team;
use App\Models\Variable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Broadcast;
use Tests\Support\Projects;

beforeEach(function (): void {
    Projects::useTestKek();
    $this->team = Team::factory()->create();
    $this->developer = memberOf($this->team, TeamRole::Developer);
    $this->viewer = memberOf($this->team, TeamRole::Viewer);
    $this->project = Projects::create($this->team);
    $this->production = $this->project->production()->sole();
    $this->api = Projects::image($this->project, 'api');
    $this->api->forceFill(['icon' => 'laravel'])->save();
    $this->web = Projects::image($this->project, 'web');
    $this->postgres = Projects::postgres($this->project);
});

test('the connect preview names variables framework-aware without creating anything', function (): void {
    $url = fn (array $query): string => route('projects.connections.preview', [$this->project, $this->production, ...$query]);

    $this->actingAs($this->viewer)->getJson($url(['from' => $this->api->public_id, 'to' => $this->postgres->public_id]))->assertForbidden();

    $this->actingAs($this->developer)->getJson($url(['from' => $this->api->public_id, 'to' => $this->postgres->public_id]))
        ->assertOk()
        ->assertJsonPath('style', 'laravel')
        ->assertJsonPath('modes', ['private'])
        ->assertJsonPath('styles', ['url', 'laravel', 'parts'])
        ->assertJsonPath('variables.0', ['key' => 'DB_CONNECTION', 'value' => 'pgsql'])
        ->assertJsonPath('variables.1', ['key' => 'DB_HOST', 'value' => '${{postgres.PGHOST}}'])
        ->assertJsonPath('error', null);

    $this->actingAs($this->developer)->getJson($url(['from' => $this->web->public_id, 'to' => $this->api->public_id, 'mode' => 'public']))
        ->assertJsonPath('variables', [['key' => 'API_URL', 'value' => '${{api.PUBLIC_URL}}']])
        ->assertJsonPath('modes', ['private', 'public']);

    $this->actingAs($this->developer)->getJson($url(['from' => $this->web->public_id, 'to' => $this->web->public_id]))
        ->assertJsonPath('error', 'A service can’t connect to itself.')
        ->assertJsonPath('variables', []);

    $foreign = Projects::image(Projects::create($this->team, 'Other'), 'x');
    $this->actingAs($this->developer)->getJson($url(['from' => $this->web->public_id, 'to' => $foreign->public_id]))->assertNotFound();
    $this->actingAs($this->developer)->getJson($url(['from' => 'nope', 'to' => $this->web->public_id]))->assertUnprocessable();

    expect(Connection::query()->count())->toBe(0);
});

test('connections are created and removed with their variables (staged)', function (): void {
    $store = route('projects.connections.store', [$this->project, $this->production]);
    $payload = ['from' => $this->api->public_id, 'to' => $this->postgres->public_id, 'style' => 'laravel'];

    $this->actingAs($this->viewer)->post($store, $payload)->assertForbidden();
    $this->actingAs($this->developer)->post($store, $payload)->assertRedirect();
    $this->actingAs($this->developer)->post($store, $payload)->assertSessionHasErrors();

    $connection = Connection::query()->sole();
    expect($connection->injected)->toContain('DB_PASSWORD')
        ->and(Variable::query()->where('connection_id', $connection->id)->count())->toBe(6);

    $this->actingAs($this->viewer)->get(route('projects.canvas', [$this->project, $this->production]))
        ->assertInertia(fn ($page) => $page->where('edges.0.id', $connection->public_id)->where('edges.0.style', 'laravel'));

    $destroy = route('projects.connections.destroy', [$this->project, $this->production, $connection]);
    $this->actingAs($this->viewer)->delete($destroy)->assertForbidden();

    $other = Projects::create($this->team, 'Other')->production()->sole();
    $this->actingAs($this->developer)->delete(route('projects.connections.destroy', [$this->project, $other, $connection]))->assertNotFound();

    $this->actingAs($this->developer)->delete($destroy)->assertRedirect();
    expect(Connection::query()->count())->toBe(0)
        ->and(Variable::query()->whereNotNull('connection_id')->count())->toBe(0);
});

test('the canvas channel admits members who may view the environment', function (): void {
    $callback = Broadcast::driver()->getChannels()['environments.{environment}'];
    $outsider = memberOf(Team::factory()->create(), TeamRole::Owner);

    expect($callback($this->viewer, $this->production))->toBeTrue()
        ->and($callback($outsider, $this->production))->toBeFalse();

    // The parameter resolves through the team-scoped binding: other teams'
    // environments are not found before the callback runs.
    $this->actingAs($outsider);
    expect(fn () => resolve('router')->getBindingCallback('environment')($this->production->public_id))
        ->toThrow(ModelNotFoundException::class);
});
