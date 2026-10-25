<?php

declare(strict_types=1);

use App\Data\Protocol\ProtocolJson;
use App\Data\Protocol\V1\ContainerInspectionResult;
use App\Data\Protocol\V1\IngressStatus;
use App\Data\Protocol\V1\InventoryResult;
use App\Data\Protocol\V1\Report;
use App\Data\Protocol\V1\RouteSpec;
use App\Data\Protocol\V1\Task;
use App\Data\Protocol\V1\TaskCompletion;
use App\Data\Protocol\V1\TaskLease;
use App\Data\Protocol\V1\WafStatus;
use App\Enums\DaemonTaskType;
use App\Support\Security\InvestigationInputs;
use App\Support\Security\InvestigationReport;
use App\Support\Security\WafSamples;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProtocolSchemas;

/*
| The security center's protocol (docs/03 §13): the Go fixtures decode into
| the PHP mirrors and re-encode to the same documents, schema-valid.
*/

/**
 * @return array<string, mixed>
 */
function securityFixture(string $name): array
{
    return json_decode((string) file_get_contents(ProtocolSchemas::root()."/fixtures/v1/{$name}.json"), true, 64, JSON_THROW_ON_ERROR);
}

test('the inventory completion decodes and re-encodes unchanged', function (): void {
    $fixture = securityFixture('task-completion.inventory');
    $completion = TaskCompletion::validateAndCreate($fixture);
    $result = InventoryResult::fromWire(is_array($completion->inventory) ? $completion->inventory : []);

    expect($result->components)->toHaveCount(8)
        ->and($result->components[2]->presence)->toBe('bundled')
        ->and($result->toProtocol())->toEqual($fixture['inventory'])
        ->and(ProtocolSchemas::errors('task-completion', (string) json_encode([...$fixture, 'inventory' => $result->toProtocol()])))->toBe([]);
});

test('the container inspection completion decodes and re-encodes unchanged', function (): void {
    $fixture = securityFixture('task-completion.container-inspection');
    $result = ContainerInspectionResult::fromWire($fixture['containerInspection']);
    // `truncated: false` is the zero value launchroomd omits.
    unset($fixture['containerInspection']['processes']['truncated']);

    expect($result->processes)->toHaveCount(3)
        ->and($result->collapsed)->toBe([['prefix' => '/app/.next/cache', 'count' => 187], ['prefix' => '/home/node/.npm', 'count' => 23]])
        ->and($result->toProtocol())->toEqual($fixture['containerInspection'])
        ->and(ProtocolSchemas::errors('task-completion', (string) json_encode([...$fixture, 'containerInspection' => $result->toProtocol()])))->toBe([]);
});

test('results that break launchroomd\'s rules are refused', function (array $patch, string $field): void {
    $fixture = securityFixture('task-completion.inventory')['inventory'];
    $document = array_replace_recursive($fixture, $patch);

    expect(fn (): InventoryResult => InventoryResult::fromWire($document))->toThrow(ValidationException::class);

    try {
        InventoryResult::fromWire($document);
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toContain($field);
    }
})->with([
    'bundled OS package' => [['components' => [6 => ['presence' => 'bundled']]], 'inventory.components.6.presence'],
    'library kind for a binary' => [['components' => [0 => ['kind' => 'library']]], 'inventory.components.0.kind'],
    'dev scope for an OS package' => [['components' => [7 => ['scope' => 'dev']]], 'inventory.components.7.scope'],
    'name with a newline' => [['components' => [1 => ['name' => "next\nx"]]], 'inventory.components.1.name'],
    'image with a tag' => [['image' => '127.0.0.1:5000/apps/x:latest'], 'inventory.image'],
    'truncated below the limit' => [['truncated' => true], 'inventory.truncated'],
    'lockfile path escaping' => [['lockfiles' => [0 => ['path' => '../etc/passwd']]], 'inventory.lockfiles.0.path'],
]);

test('inspection results with commands too long or flags unknown are refused', function (): void {
    $fixture = securityFixture('task-completion.container-inspection')['containerInspection'];
    $fixture['processes']['entries'][0]['command'] = str_repeat('a', 513);
    $fixture['changes']['entries'][0]['flags'][] = 'rootkit';

    expect(fn (): ContainerInspectionResult => ContainerInspectionResult::fromWire($fixture))->toThrow(ValidationException::class);
});

test('the firewall routes of the NodeState fixture round-trip through RouteSpec', function (): void {
    $fixture = json_decode((string) file_get_contents(ProtocolSchemas::root().'/fixtures/v1/node-state.waf.json'), false, 64, JSON_THROW_ON_ERROR);
    $routes = array_map(static fn (object $route): array => RouteSpec::fromProtocol($route)->toProtocol(), $fixture->routes);

    expect(json_decode((string) json_encode($routes), true))->toEqual(securityFixture('node-state.waf')['routes']);
});

test('the firewall report decodes: state, samples and events', function (): void {
    $fixture = securityFixture('report.waf');
    $report = Report::validateAndCreate($fixture);
    $rows = WafSamples::parse($report->metrics, CarbonImmutable::parse('2026-10-17T12:10:05Z'));

    expect($report->ingress)->toBeInstanceOf(IngressStatus::class)
        ->and($report->ingress instanceof IngressStatus ? $report->ingress->waf : null)->toBeInstanceOf(WafStatus::class)
        ->and($rows)->toHaveCount(2)
        ->and($rows[0]['blocked'])->toBe(14)
        ->and($rows[0]['other_paths_blocked'])->toBe(3)
        ->and(array_column($rows[0]['events'], 'client_network'))->toBe(['203.0.113.0/24', '2001:db8::/48'])
        ->and($rows[1]['events'][0]['rules'])->toBe([941100, 941160]);
});

test('the inventory and inspection lease fixtures round-trip through the PHP task document', function (string $name): void {
    $lease = json_decode((string) file_get_contents(ProtocolSchemas::root()."/fixtures/v1/{$name}.json"), false);
    $tasks = array_map(static fn (object $task): Task => new Task($task->id, DaemonTaskType::from($task->type), CarbonImmutable::parse($task->leaseExpiresAt), $task->attempt, CarbonImmutable::parse($task->deadline), $task->spec), $lease->tasks);
    $encoded = ProtocolJson::encode(new TaskLease($tasks));

    expect(ProtocolSchemas::errors('task-lease', $encoded))->toBe([])
        ->and(json_decode($encoded, true))->toEqual(securityFixture($name));
})->with(['task-lease.inventory', 'task-lease.inspect-container']);

test('without parent PIDs the report says so and judges no process by its parent', function (): void {
    $fixture = securityFixture('task-completion.container-inspection')['containerInspection'];
    $fixture['processes']['parentsUnknown'] = true;

    foreach ($fixture['processes']['entries'] as $i => $entry) {
        $fixture['processes']['entries'][$i]['ppid'] = 0;
    }

    $result = ContainerInspectionResult::fromWire($fixture);
    expect($result->parentsUnknown)->toBeTrue()
        ->and($result->toProtocol()['processes']['parentsUnknown'])->toBeTrue();

    $now = CarbonImmutable::now();
    $report = InvestigationReport::build(new InvestigationInputs(
        service: 'api', now: $now, replicas: [['server' => 'server-2', 'state' => 'done', 'message' => null, 'result' => $result]],
        sessions: [], wafMode: 'block', categories: [], events: [], wafTotals: ['blocked' => 0, 'detected' => 0], hours: [],
        liveSince: null, connections: [], secrets: [], volumes: [], domains: [], finding: null, hrefs: [],
    ));

    expect(collect($report->checked)->pluck('note')->filter()->all())->toContain("Process parents weren't available on server-2, so processes couldn't be traced to what started them.")
        ->and(collect($report->found)->pluck('text')->filter(fn (string $text): bool => str_contains($text, 'started by'))->all())->toBe([]);
});
