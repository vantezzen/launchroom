<?php

declare(strict_types=1);

use App\Data\Logs\LogEntryData;
use App\Data\Logs\LogQueryData;
use App\Enums\LogSourceKind;
use App\Enums\TeamRole;
use App\Models\Deployment;
use App\Models\Placement;
use App\Queries\LogSearch;
use App\Support\Logs\LogScope;
use Carbon\CarbonImmutable;
use Tests\Support\DeploymentUi;
use Tests\Support\LogChunks;
use Tests\Support\Projects;

/*
| Log download (docs/08 §12) and absolute windows by line time: the file
| holds what the same search shows, scoped and authorized like the search
| endpoints, at most LogSearch::EXPORT_LIMIT lines.
*/

beforeEach(function (): void {
    Projects::useTestKek();
});

/**
 * One shipped chunk: `$lines` are [text, line time] pairs; the chunk is
 * stored at `$storedAt` (after its lines, like a real batch).
 *
 * @param  list<array{string, CarbonImmutable}>  $lines
 */
function downloadChunk(DeploymentUi $world, Deployment $deployment, array $lines, CarbonImmutable $storedAt): void
{
    $placement = Placement::query()->where('deployment_id', $deployment->id)->firstOrFail();

    LogChunks::create([
        'team_id' => $deployment->team_id, 'server_id' => $world->server->id, 'deployment_id' => $deployment->id,
        'source_kind' => LogSourceKind::Workload, 'source_id' => $placement->workload_id,
        'seq' => random_int(1, 1_000_000),
        'lines' => array_map(static fn (array $line): array => ['time' => $line[1]->utc()->format('Y-m-d\TH:i:s.v\Z'), 'stream' => 'stdout', 'text' => $line[0]], $lines),
        'created_at' => $storedAt,
    ]);
}

test('an absolute window keeps the lines whose own time is inside it, whenever their chunk was stored', function (): void {
    $world = DeploymentUi::make();
    $now = CarbonImmutable::now();
    downloadChunk($world, $world->latest($world->web), [
        ['before the window', $now->subMinutes(30)],
        ['inside the window', $now->subMinutes(8)],
    ], $now->subMinutes(6));

    $page = $this->actingAs($world->member(TeamRole::Viewer))
        ->getJson(route('projects.logs.entries', $world->envArgs()).'?range=1h&from='.$now->subMinutes(10)->getTimestamp().'&to='.$now->subMinutes(7)->getTimestamp())
        ->assertOk()->json();

    expect(collect($page['entries'])->pluck('message')->all())->toBe(['inside the window'])
        ->and($page['matches'])->toBe(1)
        ->and(array_sum(array_column($page['buckets'], 'total')))->toBe(1);
});

test('members download an environment\'s matching lines as text or NDJSON', function (): void {
    $world = DeploymentUi::make();
    $now = CarbonImmutable::now();
    downloadChunk($world, $world->latest($world->web), [
        ["\e[31mboom\e[0m first", $now->subMinutes(3)],
        ['quiet second', $now->subMinutes(2)],
    ], $now->subMinute());
    $viewer = $world->member(TeamRole::Viewer);
    $url = route('projects.logs.download', $world->envArgs()).'?range=1h&source=runtime';

    $text = $this->actingAs($viewer)->get($url)->assertOk();
    expect($text->headers->get('content-type'))->toStartWith('text/plain')
        ->and($text->headers->get('content-disposition'))->toContain('attachment')->toContain('.log');
    $lines = explode("\n", trim($text->streamedContent()));
    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toContain('web')->toEndWith('boom first')
        ->and($lines[1])->toEndWith('quiet second');

    $ndjson = $this->actingAs($viewer)->get($url.'&format=ndjson&q=quiet')->assertOk();
    $rows = array_map(static fn (string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR), explode("\n", trim($ndjson->streamedContent())));
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['message'])->toBe('quiet second')
        ->and(array_keys($rows[0]))->toEqualCanonicalizing(array_keys(resolve(LogSearch::class)->export(LogScope::environment($world->production), new LogQueryData(q: 'quiet'))[0]->toArray()));

    $this->actingAs($viewer)->get($url.'&format=html')->assertSessionHasErrors('format');
});

test('downloads are scoped like the search: other teams get 404, the team file is for owners and admins', function (): void {
    $world = DeploymentUi::make();
    $other = DeploymentUi::make();
    $now = CarbonImmutable::now();
    downloadChunk($world, $world->latest($world->web), [['needle in shop', $now->subMinutes(2)]], $now->subMinute());
    downloadChunk($other, $other->latest($other->web), [['needle elsewhere', $now->subMinutes(2)]], $now->subMinute());
    $outsider = $other->member(TeamRole::Owner);

    $this->actingAs($outsider)->get(route('projects.logs.download', $world->envArgs()))->assertNotFound();
    $this->actingAs($outsider)->get(route('projects.logs.project-download', ['project' => $world->project->public_id]))->assertNotFound();

    $project = $this->actingAs($world->member(TeamRole::Viewer))->get(route('projects.logs.project-download', ['project' => $world->project->public_id]).'?q=needle')->assertOk();
    expect($project->streamedContent())->toContain('needle in shop')->not->toContain('elsewhere');

    $this->actingAs($world->member(TeamRole::Developer))->get(route('logs.download'))->assertForbidden();
    $team = $this->actingAs($world->member(TeamRole::Admin))->get(route('logs.download').'?q=needle')->assertOk();
    expect($team->streamedContent())->toContain('needle in shop')->not->toContain('elsewhere');

    $this->app['auth']->forgetGuards();
    expect($this->get(route('logs.download'))->status())->toBeIn([302, 401, 404]);
});

test('a download holds at most the newest EXPORT_LIMIT lines, oldest first', function (): void {
    $world = DeploymentUi::make();
    $now = CarbonImmutable::now();
    downloadChunk($world, $world->latest($world->web), array_map(
        static fn (int $i): array => ["line {$i}", $now->subMinutes(10)->addSeconds($i)],
        range(1, 5),
    ), $now->subMinute());

    $entries = resolve(LogSearch::class)->export(LogScope::environment($world->production), new LogQueryData, limit: 3);

    expect(array_map(static fn (LogEntryData $entry): string => $entry->message, $entries))->toBe(['line 3', 'line 4', 'line 5'])
        ->and(LogSearch::EXPORT_LIMIT)->toBe(10000);
});
