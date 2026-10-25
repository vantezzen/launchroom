<?php

declare(strict_types=1);

use App\Actions\Domains\AddDomain;
use App\Data\Audit\AuditActor;
use App\Enums\ApiAbility;
use App\Enums\TeamRole;
use App\Enums\Transfers\DataSubject;
use App\Enums\Transfers\ExportRunStatus;
use App\Enums\Transfers\ExportSource;
use App\Enums\Transfers\ImportRunStatus;
use App\Enums\Transfers\ImportSourceKind;
use App\Enums\Transfers\TransferDirection;
use App\Enums\Transfers\TransferPurpose;
use App\Enums\VariableType;
use App\Http\Middleware\RequireTokenAbility;
use App\Models\Security\SecurityFinding;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataImportRun;
use App\Models\Transfers\DataTransfer;
use App\Services\Transfers\TransferStore;
use App\Support\PublicId;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Support\Api;
use Tests\Support\DeploymentUi;
use Tests\Support\Projects;

/*
| Every /api/v1 route declares one ability (docs/16 §1.2). A token without it
| is refused with 403 missing_ability before anything else happens; a token
| with only that ability (and read) is never refused for the ability.
*/

beforeEach(fn () => Projects::useTestKek());

/**
 * @return list<array{Route, string, ApiAbility|null}>
 */
function apiRoutes(): array
{
    $routes = [];

    foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        $ability = null;

        foreach (RouteFacade::gatherRouteMiddleware($route) as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, RequireTokenAbility::class.':')) {
                $ability = ApiAbility::from(substr($middleware, strlen(RequireTokenAbility::class) + 1));
            }
        }

        $routes[] = [$route, array_values(array_diff($route->methods(), ['HEAD']))[0], $ability];
    }

    return $routes;
}

/**
 * Real rows for the data transfer routes (bindings resolve before abilities).
 *
 * @return array{transfer: string, import: string, export: string}
 */
function transferIds(Team $team, ServiceInstance $instance): array
{
    $transfer = new DataTransfer([
        'team_id' => $team->id, 'service_instance_id' => $instance->id, 'direction' => TransferDirection::Upload, 'purpose' => TransferPurpose::DatabaseImport,
        'file_name' => 'a.dump', 'size_bytes' => 1, 'chunk_bytes' => 1048576, 'chunk_count' => 1, 'expires_at' => now()->addDay(),
    ]);
    $transfer->public_id = PublicId::generate(DataTransfer::publicIdPrefix());
    resolve(TransferStore::class)->initialise($transfer);
    $transfer->save();

    $import = new DataImportRun([
        'team_id' => $team->id, 'service_instance_id' => $instance->id, 'subject' => DataSubject::Database, 'source_kind' => ImportSourceKind::File, 'source_label' => 'a.dump',
    ]);
    $import->status = ImportRunStatus::Failed;
    $import->save();

    $export = new DataExportRun([
        'team_id' => $team->id, 'service_instance_id' => $instance->id, 'subject' => DataSubject::Database, 'source' => ExportSource::Live, 'file_name' => 'a.dump',
    ]);
    $export->status = ExportRunStatus::Failed;
    $export->save();

    return ['transfer' => $transfer->public_id, 'import' => $import->public_id, 'export' => $export->public_id];
}

/**
 * @param  array<string, string>  $ids
 */
function apiUri(Route $route, array $ids): string
{
    return (string) preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => $ids[$m[1]], substr($route->uri(), strlen('api/v1/')));
}

test('every authenticated API route declares exactly one ability, except /me', function (): void {
    $missing = [];

    foreach (apiRoutes() as [$route, $method, $ability]) {
        $public = in_array($route->uri(), ['api/v1/openapi.json', 'api/v1/cli/sessions', 'api/v1/cli/sessions/token'], true);

        if (! $public && $route->uri() !== 'api/v1/me' && $ability === null) {
            $missing[] = "{$method} {$route->uri()}";
        }
    }

    expect($missing)->toBe([]);
});

test('a token without the route\'s ability gets 403 missing_ability; with it, never', function (): void {
    config(['api.streams.max_seconds' => 0]);
    $world = DeploymentUi::make();
    $owner = $world->member(TeamRole::Owner);
    $variable = $world->variable('APP_NAME', 'shop');
    $instance = ServiceInstance::query()->where('environment_id', $world->production->id)->where('service_id', $world->web->id)->sole();
    $domain = resolve(AddDomain::class)->handle($instance, 'shop.example.com', AuditActor::system());

    $ids = [
        'project' => $world->project->public_id,
        'environment' => $world->production->public_id,
        'service' => $world->web->public_id,
        'deployment' => $world->latest($world->web)->public_id,
        'variable' => $variable->public_id,
        'domain' => $domain->public_id,
        'server' => $world->server->public_id,
        // Data import and export (docs/14 §12.7).
        ...transferIds($world->team, $instance),
        'index' => '0',
        'token' => 'lr_dl_'.str_repeat('A', 43),
        // Security center (docs/22 §11).
        'finding' => SecurityFinding::query()->forceCreate([
            'team_id' => $world->team->id, 'project_id' => $world->project->id, 'environment_id' => $world->production->id,
            'service_instance_id' => $instance->id, 'group_key' => 'GHSA-0000-0000-0000', 'ecosystem' => 'npm', 'name' => 'lodash',
            'version' => '4.17.20', 'kind' => 'library', 'scope' => 'runtime', 'presence' => 'installed', 'level' => 'worth_knowing',
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ])->public_id,
    ];

    $checked = 0;

    foreach (apiRoutes() as [$route, $method, $ability]) {
        if ($ability === null) {
            continue;
        }

        $without = array_values(array_filter(ApiAbility::values(), static fn (string $value): bool => $value !== $ability->value));
        $response = Api::call($method, apiUri($route, $ids), Api::rawToken($owner, $world->team, $without), ['service' => $ids['service']]);

        expect($response->getStatusCode())->toBe(403, "{$method} {$route->uri()} without {$ability->value}")
            ->and($response->json('error.code'))->toBe('missing_ability')
            ->and($response->json('error.ability'))->toBe($ability->value);

        $only = array_values(array_unique([ApiAbility::Read->value, $ability->value]));
        $allowed = Api::call($method, apiUri($route, $ids), Api::rawToken($owner, $world->team, $only), ['service' => $ids['service']]);

        $refused = $allowed->getStatusCode() === 403 && $allowed->json('error.code') === 'missing_ability';
        expect($refused)->toBeFalse("{$method} {$route->uri()} with {$ability->value}");
        $checked++;
    }

    expect($checked)->toBeGreaterThan(20);
});

test('abilities narrow the role: a read token of an owner cannot write variables', function (): void {
    $world = DeploymentUi::make();
    $owner = $world->member(TeamRole::Owner);
    $plain = Api::token($owner, $world->team, [ApiAbility::Read]);

    Api::call('POST', "environments/{$world->production->public_id}/services/{$world->web->public_id}/variables", $plain, ['key' => 'X', 'value' => '1'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'missing_ability')
        ->assertJsonPath('error.ability', 'env:write');
});

test('role matrix: what each role\'s full token may do', function (TeamRole $role, bool $deploy, bool $writeVariables, bool $revealSecrets, bool $domains): void {
    $world = DeploymentUi::make();
    $member = $world->member($role);
    $plain = Api::rawToken($member, $world->team, ApiAbility::values());
    $secret = $world->variable('API_SECRET', 's3cret', VariableType::Secret);
    $env = $world->production->public_id;

    expect(Api::call('POST', "deployments/{$world->latest($world->web)->public_id}/redeploy", $plain)->status() === 202)->toBe($deploy)
        ->and(Api::call('POST', "environments/{$env}/services/{$world->web->public_id}/variables", $plain, ['key' => 'NEW_ONE', 'value' => '1'])->status() === 201)->toBe($writeVariables)
        ->and(Api::get("environments/{$env}/variables/{$secret->public_id}/value", $plain)->status() === 200)->toBe($revealSecrets)
        ->and(Api::call('POST', "environments/{$env}/services/{$world->web->public_id}/domains", $plain, ['hostname' => strtolower($role->value).'.example.com'])->status() === 201)->toBe($domains);
})->with([
    'owner' => [TeamRole::Owner, true, true, true, true],
    'admin' => [TeamRole::Admin, true, true, true, true],
    'developer' => [TeamRole::Developer, true, true, false, true],
    'viewer' => [TeamRole::Viewer, false, false, false, false],
]);
