<?php

declare(strict_types=1);

use App\Actions\Deployments\DeployEnvironment;
use App\Actions\Environments\CreateEnvironment;
use App\Actions\Promotions\PromoteChanges;
use App\Data\Audit\AuditActor;
use App\Data\Environments\CreateEnvironmentData;
use App\Data\Promotions\PromoteChangesData;
use App\Data\Services\ConfigFile;
use App\Enums\DomainKind;
use App\Enums\TeamRole;
use App\Enums\VariableType;
use App\Models\AuditEvent;
use App\Models\Connection;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\User;
use App\Models\Variable;
use App\Models\Volume;
use App\Services\Promotions\PromotionPlanner;
use App\Services\Variables\VariableStore;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitHubFake;
use Tests\Support\Projects;

beforeEach(function (): void {
    GitHubFake::setUp();
    Projects::useTestKek();
    Queue::fake();
    $this->team = Team::factory()->create();
    $this->developer = memberOf($this->team, TeamRole::Developer);
});

/**
 * @return array<string, mixed> the payload of a JWT whose signature verifies with $secret
 */
function verifiedJwt(string $token, string $secret): array
{
    [$header, $payload, $signature] = explode('.', $token);
    $decode = static fn (string $part): string => (string) base64_decode(strtr($part, '-_', '+/'), true);

    expect(json_decode($decode($header), true))->toBe(['alg' => 'HS256', 'typ' => 'JWT'])
        ->and(hash_equals(hash_hmac('sha256', "{$header}.{$payload}", $secret, true), $decode($signature)))->toBeTrue('The JWT signature doesn’t verify.');

    return json_decode($decode($payload), true, 4, JSON_THROW_ON_ERROR);
}

function supabaseVariable(Project $project, string $service, string $key, ?Environment $environment = null): Variable
{
    return Variable::query()
        ->where('project_id', $project->id)
        ->whereRelation('service', 'slug', $service)
        ->where('key', $key)
        ->when($environment instanceof Environment, static fn ($query) => $query->whereRelation('targets', 'environment_id', $environment?->id))
        ->sole();
}

test('Supabase is one public gateway in front of private services, with API keys signed by its JWT secret', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });

    Projects::server($this->team);

    $response = $this->actingAs($this->developer)->post(route('projects.templates.store', 'supabase'), [
        'name' => 'Backend', 'deploy' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $project = Project::query()->where('name', 'Backend')->sole();
    $services = Service::query()->where('project_id', $project->id)->get()->keyBy('slug');
    $store = resolve(VariableStore::class);
    $secret = $store->plaintext($project, supabaseVariable($project, 'supabase', 'JWT_SECRET'));
    $anon = supabaseVariable($project, 'supabase', 'ANON_KEY');
    $serviceRole = supabaseVariable($project, 'supabase', 'SERVICE_ROLE_KEY');
    $anonPayload = verifiedJwt($store->plaintext($project, $anon), $secret);
    $rolePayload = verifiedJwt($store->plaintext($project, $serviceRole), $secret);
    $public = Domain::query()->where('kind', DomainKind::Generated->value)->with('serviceInstance.service')->get()
        ->map(fn (Domain $domain): string => $domain->serviceInstance->service->slug)->unique()->values()->all();
    $db = ServiceInstance::query()->where('service_id', $services['postgres']->id)->sole();

    // Slugs come from the services' names; references follow them.
    expect($services->keys()->sort()->values()->all())->toBe(['auth', 'postgres', 'postgres-meta', 'realtime', 'rest-api', 'storage', 'studio', 'supabase'])
        // Only the gateway is reachable from outside; Studio is behind its basic auth.
        ->and($public)->toBe(['supabase'])
        ->and(strlen($secret))->toBe(48)
        ->and($anonPayload['role'])->toBe('anon')
        ->and($anonPayload['iss'])->toBe('supabase')
        ->and($anonPayload['exp'] - $anonPayload['iat'])->toBeGreaterThan(5 * 365 * 24 * 3600)
        ->and($rolePayload['role'])->toBe('service_role')
        // The anon key is public by design (browser bundles); the service role key is a secret.
        ->and($anon->type)->toBe(VariableType::Config)
        ->and($serviceRole->type)->toBe(VariableType::Secret)
        ->and($anon->generator)->toBe('jwt("anon",JWT_SECRET)')
        ->and($services['supabase']->connection_variables)->toBe([
            ['key' => 'NEXT_PUBLIC_SUPABASE_URL', 'value' => '${{PUBLIC_URL}}'],
            ['key' => 'NEXT_PUBLIC_SUPABASE_ANON_KEY', 'value' => '${{ANON_KEY}}'],
            ['key' => 'SUPABASE_SERVICE_ROLE_KEY', 'value' => '${{SERVICE_ROLE_KEY}}'],
        ])
        ->and(array_map(static fn (ConfigFile $file): string => $file->path, $db->deploy->files))->toBe([
            '/docker-entrypoint-initdb.d/init-scripts/98-webhooks.sql',
            '/docker-entrypoint-initdb.d/init-scripts/99-roles.sql',
            '/docker-entrypoint-initdb.d/init-scripts/99-jwt.sql',
            '/docker-entrypoint-initdb.d/migrations/99-realtime.sql',
        ])
        ->and($db->deploy->files[1]->content)->toContain('echo "$POSTGRES_PASSWORD"')
        ->and($db->deploy->capabilities)->toContain('SETUID')
        // Postgres writes its files in place: volume backups freeze it while reading.
        ->and(Volume::query()->where('service_instance_id', $db->id)->pluck('backup_consistency')->map->value->unique()->all())->toBe(['pause'])
        ->and($store->plaintext($project, supabaseVariable($project, 'supabase', 'KONG_DECLARATIVE_CONFIG_STRING')))
        ->toContain('"key":"${{ANON_KEY}}"')
        ->toContain('"url":"http://${{auth.PRIVATE_HOST}}:9999/verify"')
        ->toContain('{"name":"basic-auth"')
        // One line: Kong would cut a value with line breaks.
        ->not->toContain("\n");

    // Generated secrets never leave the server: not in logs, audit entries or the response.
    $audit = AuditEvent::query()->get()->map(fn (AuditEvent $event): string => (string) json_encode($event->meta))->implode("\n");

    foreach ([$secret, $store->plaintext($project, $serviceRole)] as $value) {
        expect(implode("\n", $logged))->not->toContain($value)
            ->and($audit)->not->toContain($value)
            ->and((string) $response->getContent())->not->toContain($value);
    }
});

test('another environment gets its own JWT secret and keys signed with it', function (): void {
    $this->actingAs($this->developer)->post(route('projects.templates.store', 'supabase'), ['name' => 'Backend', 'deploy' => false])->assertRedirect();
    $project = Project::query()->where('name', 'Backend')->sole();
    $production = $project->production()->sole();
    $staging = resolve(CreateEnvironment::class)->handle($project, new CreateEnvironmentData('staging'), null, AuditActor::system());
    $store = resolve(VariableStore::class);

    $secret = static fn (Environment $environment): string => $store->plaintext($project, supabaseVariable($project, 'supabase', 'JWT_SECRET', $environment));
    $key = static fn (Environment $environment, string $name): string => $store->plaintext($project, supabaseVariable($project, 'supabase', $name, $environment));

    expect($secret($staging))->not->toBe($secret($production))
        ->and($key($staging, 'ANON_KEY'))->not->toBe($key($production, 'ANON_KEY'))
        ->and(verifiedJwt($key($staging, 'ANON_KEY'), $secret($staging))['role'])->toBe('anon')
        ->and(verifiedJwt($key($staging, 'SERVICE_ROLE_KEY'), $secret($staging))['role'])->toBe('service_role');
});

test('added to an existing project, Supabase hands a connected app its URL and keys', function (): void {
    $project = Projects::create($this->team);
    $environment = $project->production()->sole();
    $web = Projects::image($project, 'web', port: 3000);
    $web->forceFill(['icon' => 'nextjs'])->save();
    // A service named like one of Supabase's: the template's gets the next free name.
    Projects::image($project, 'auth');

    $this->actingAs($this->developer)->post(route('projects.catalog.store', [$project, $environment, 'supabase']))
        ->assertRedirect(route('projects.canvas', [$project, $environment]));

    $kong = Service::query()->where('project_id', $project->id)->where('slug', 'supabase')->sole();
    $store = resolve(VariableStore::class);

    expect($store->plaintext($project, supabaseVariable($project, 'supabase', 'KONG_DECLARATIVE_CONFIG_STRING')))
        ->toContain('"url":"http://${{auth-2.PRIVATE_HOST}}:9999/verify"');

    $this->actingAs($this->developer)->getJson(route('projects.connections.preview', [$project, $environment, 'from' => $web->public_id, 'to' => $kong->public_id]))
        ->assertOk()
        ->assertJsonPath('modes', ['private'])
        ->assertJsonPath('variables', [
            ['key' => 'NEXT_PUBLIC_SUPABASE_URL', 'value' => '${{supabase.PUBLIC_URL}}'],
            ['key' => 'NEXT_PUBLIC_SUPABASE_ANON_KEY', 'value' => '${{supabase.ANON_KEY}}'],
            ['key' => 'SUPABASE_SERVICE_ROLE_KEY', 'value' => '${{supabase.SERVICE_ROLE_KEY}}'],
        ]);

    $this->actingAs($this->developer)->post(route('projects.connections.store', [$project, $environment]), [
        'from' => $web->public_id, 'to' => $kong->public_id, 'style' => 'url',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $connection = Connection::query()->where('from_service_id', $web->id)->sole();
    $injected = Variable::query()->where('connection_id', $connection->id)->get()
        ->mapWithKeys(fn (Variable $variable): array => [$variable->key => $store->plaintext($project, $variable)])->sortKeys()->all();

    expect($injected)->toBe([
        'NEXT_PUBLIC_SUPABASE_ANON_KEY' => '${{supabase.ANON_KEY}}',
        'NEXT_PUBLIC_SUPABASE_URL' => '${{supabase.PUBLIC_URL}}',
        'SUPABASE_SERVICE_ROLE_KEY' => '${{supabase.SERVICE_ROLE_KEY}}',
    ]);
});

test('promoted into an environment without it, the keys are generated after the secret they are signed with', function (): void {
    Projects::server($this->team, memoryMb: 16384);
    $project = Projects::create($this->team);
    Projects::image($project, 'web');
    $production = $project->production()->sole();
    resolve(DeployEnvironment::class)->handle($production, null, AuditActor::system());
    $staging = resolve(CreateEnvironment::class)->handle($project, new CreateEnvironmentData('staging'), null, AuditActor::system());

    // Tried in staging first, then promoted (Compare & promote works on what was deployed).
    $this->actingAs($this->developer)->post(route('projects.catalog.store', [$project, $staging, 'supabase']))->assertRedirect();
    resolve(DeployEnvironment::class)->handle($staging->refresh(), null, AuditActor::system());

    $gateway = Service::query()->where('project_id', $project->id)->where('slug', 'supabase')->sole();
    $items = collect(resolve(PromotionPlanner::class)->plan($staging, $production)->items)->keyBy('path');
    $anonPath = "services.{$gateway->public_id}.variables.ANON_KEY";
    $secretPath = "services.{$gateway->public_id}.variables.JWT_SECRET";

    expect($items[$anonPath]->dependsOn)->toContain($secretPath);

    resolve(PromoteChanges::class)->handle($project, PromoteChangesData::from([
        'from' => $staging->public_id,
        'to' => $production->public_id,
        // Secret references (`${{supabase.JWT_SECRET}}`) are copied as they are: they point at production's own values.
        'items' => $items->filter(fn ($item): bool => $item->selected)->map(fn ($item): array => ['path' => $item->path, 'copyValue' => $item->needsValue])->values()->all(),
        'images' => [],
    ]), User::factory()->create(), AuditActor::system());

    $store = resolve(VariableStore::class);
    $secret = $store->plaintext($project, supabaseVariable($project, 'supabase', 'JWT_SECRET', $production));

    expect($secret)->not->toBe($store->plaintext($project, supabaseVariable($project, 'supabase', 'JWT_SECRET', $staging)))
        ->and(verifiedJwt($store->plaintext($project, supabaseVariable($project, 'supabase', 'ANON_KEY', $production)), $secret)['role'])->toBe('anon')
        ->and(verifiedJwt($store->plaintext($project, supabaseVariable($project, 'supabase', 'SERVICE_ROLE_KEY', $production)), $secret)['role'])->toBe('service_role');
});
