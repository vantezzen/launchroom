<?php

declare(strict_types=1);

use App\Data\Canvas\DetectedPlanData;
use App\Data\Projects\PlanSuggestionsData;
use App\Data\Protocol\V1\BuildSummary;
use App\Data\Specs\EnvironmentSpec;
use App\Enums\DatabaseEngine;
use App\Models\Project;
use App\Services\Variables\ReplicaWarnings;

/*
| What the plan asks of the user (G48, G56, G62, G65): required secrets,
| the Laravel SQLite warning and Postgres preselected on import, volume
| suggestions, the ISR warning only with several replicas, and what breaks
| when a Laravel app scales out.
*/

/**
 * @param  array<string, mixed>  $extra
 */
function paSummary(string $framework, array $extra = []): BuildSummary
{
    return BuildSummary::from(['builder' => 'railpack', 'builderReason' => 'preset', 'framework' => $framework, ...$extra]);
}

test('the ISR warning shows only with more than one replica', function (): void {
    $summary = paSummary('next', ['warnings' => [
        ['code' => 'isr-cache-warning', 'message' => 'With more than one replica, ISR needs a shared cache.'],
        ['code' => 'standalone-off', 'message' => 'Standalone output is off.'],
    ]]);

    expect(DetectedPlanData::fromSummary($summary, [], 1)->warnings)->toBe(['Standalone output is off.'])
        ->and(DetectedPlanData::fromSummary($summary, [], 3)->warnings)->toBe(['With more than one replica, ISR needs a shared cache.', 'Standalone output is off.']);
});

test('required secrets are asked for until the service has them', function (): void {
    $summary = paSummary('rails', [
        'requiredSecrets' => [['name' => 'RAILS_MASTER_KEY', 'reason' => 'the contents of config/master.key']],
        'envReferences' => ['RAILS_MASTER_KEY'],
        'volumeSuggestions' => [['path' => '/rails/storage', 'reason' => 'The SQLite database and uploads live here.']],
    ]);

    $preview = DetectedPlanData::fromSummary($summary);
    $missing = DetectedPlanData::fromSummary($summary, []);
    $set = DetectedPlanData::fromSummary($summary, ['RAILS_MASTER_KEY']);

    expect($preview->missingSecrets)->toBe(['RAILS_MASTER_KEY'])
        ->and($missing->missingSecrets)->toBe(['RAILS_MASTER_KEY'])
        // Asked once, not again as "referenced but not defined".
        ->and($missing->missingVariables)->toBe([])
        ->and($set->missingSecrets)->toBe([])
        ->and($set->requiredSecrets[0]->reason)->toBe('the contents of config/master.key')
        ->and($set->volumeSuggestions[0]->path)->toBe('/rails/storage');
});

test('a Laravel app without a database is warned about its SQLite file', function (): void {
    $summary = paSummary('laravel');

    expect(DetectedPlanData::fromSummary($summary, ['APP_NAME'])->sqliteWarning)->toContain('SQLite')
        ->and(DetectedPlanData::fromSummary($summary, ['DB_CONNECTION', 'DB_HOST'])->sqliteWarning)->toBeNull()
        // Plan previews don't know the variables yet: the import preselects Postgres instead.
        ->and(DetectedPlanData::fromSummary($summary)->sqliteWarning)->toBeNull()
        ->and(DetectedPlanData::fromSummary(paSummary('next'), [])->sqliteWarning)->toBeNull();
});

test('importing a Laravel app preselects Postgres as a fallback', function (): void {
    $suggestions = PlanSuggestionsData::fromSummary(paSummary('laravel'), 'apps/api/');

    expect($suggestions->databases)->toHaveCount(1)
        ->and($suggestions->databases[0]->engine)->toBe(DatabaseEngine::Postgres)
        ->and($suggestions->databases[0]->fallback)->toBeTrue()
        ->and($suggestions->databases[0]->apps)->toBe(['apps/api'])
        ->and(PlanSuggestionsData::fromSummary(paSummary('next'), '')->databases)->toBe([]);
});

/**
 * @param  array<string, mixed>  $web
 * @param  array<string, mixed>|null  $worker
 */
function paSpec(array $web = [], ?array $worker = null): EnvironmentSpec
{
    $service = static fn (string $id, string $slug, string $kind, ?string $parent, array $extra): array => [
        'id' => $id, 'instanceId' => 'sin'.substr($id, 3), 'slug' => $slug, 'name' => $slug, 'kind' => $kind, 'parentId' => $parent,
        'source' => ['type' => 'git', 'repositoryId' => 1, 'fullName' => 'acme/shop'], 'icon' => null, 'enabled' => true,
        'branchOverride' => null, 'build' => [], 'deploy' => [], 'scaling' => [], ...$extra,
    ];
    $services = ['svc_01J9Z8X7W6V5T4S3R2Q1P0N9W1' => $service('svc_01J9Z8X7W6V5T4S3R2Q1P0N9W1', 'web', 'app', null, $web)];

    if ($worker !== null) {
        $services['svc_01J9Z8X7W6V5T4S3R2Q1P0N9W2'] = $service('svc_01J9Z8X7W6V5T4S3R2Q1P0N9W2', 'scheduler', 'worker', 'svc_01J9Z8X7W6V5T4S3R2Q1P0N9W1', $worker);
    }

    return EnvironmentSpec::from([
        'environment' => ['id' => 'env_01J9Z8X7W6V5T4S3R2Q1P0N9E1', 'slug' => 'production', 'name' => 'production', 'kind' => 'production', 'projectId' => 'prj_01J9Z8X7W6V5T4S3R2Q1P0N9P1', 'projectSlug' => 'shop'],
        'services' => $services,
    ]);
}

test('scaling a Laravel app out warns about per-replica sessions and a scheduler', function (): void {
    $spec = paSpec(worker: ['deploy' => ['startCommand' => ['php', 'artisan', 'schedule:work']]]);
    $web = $spec->service('svc_01J9Z8X7W6V5T4S3R2Q1P0N9W1');
    $scheduler = $spec->service('svc_01J9Z8X7W6V5T4S3R2Q1P0N9W2');
    expect($web)->not->toBeNull()->and($scheduler)->not->toBeNull();

    $warnings = resolve(ReplicaWarnings::class);
    $project = new Project;
    $laravel = paSummary('laravel');

    expect($warnings->for($project, $spec, $web, $laravel))->toHaveCount(1)
        ->and($warnings->for($project, $spec, $web, $laravel)[0])->toContain('SESSION_DRIVER')
        ->and($warnings->for($project, $spec, $scheduler, $laravel)[0])->toContain('scheduler')
        ->and($warnings->for($project, $spec, $web, paSummary('next')))->toBe([])
        ->and($warnings->for($project, $spec, $web, paSummary('next', ['warnings' => [['code' => 'isr-cache-warning', 'message' => 'ISR needs a shared cache.']]])))->toBe(['ISR needs a shared cache.']);
});
