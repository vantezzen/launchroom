<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Enums\Security\AdvisoryFeed;
use App\Jobs\Security\EvaluateSecurityFindingsJob;
use App\Models\Security\AdvisoryFeedState;
use App\Models\Security\ImageInventory;
use App\Services\Security\FeedRejected;
use App\Services\Security\FeedUnavailable;
use App\Services\Security\OsvClient;
use App\Support\Metrics\Cast;
use App\Support\Security\OsvRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Asks osv.dev which advisories affect the inventoried components
 * (docs/22 §4.3): the components of one new inventory, or of every ready
 * inventory every 6 hours ("Check now": all of them, regardless of age).
 *
 * - Query cache (`advisory_queries`): one row per OSV package version; a
 *   row younger than 6 hours isn't asked again (except when forced).
 * - Up to 1,000 queries per querybatch, one request at a time, result
 *   pages followed; a batch osv.dev refuses with a 4xx is split until the
 *   one refused query is marked failed and the rest go through.
 * - New ids and ids osv.dev reports as modified are fetched as full
 *   records (`advisories`); a record that fails its grammar is dropped.
 * - 429/5xx: back off 30 s doubling to 30 min (Retry-After honoured); the
 *   keyset cursor in `advisory_feeds` lets the next run resume. Nothing is
 *   raised or resolved because data is missing (§4.4).
 *
 * Teams whose inventories were checked are re-evaluated afterwards.
 *
 * @phpstan-type Query array{ecosystem: string, name: string, version: string}
 */
final readonly class SyncAdvisories
{
    public const int BATCH = 1000;

    private const int MAX_PAGES = 10;

    public function __construct(
        private OsvClient $osv,
    ) {}

    /**
     * @return array{queries: int, records: int, complete: bool, retryAfter: int|null}
     */
    public function handle(?ImageInventory $only = null, bool $force = false): array
    {
        $feed = AdvisoryFeedState::of(AdvisoryFeed::Osv);

        if (! $feed->enabled) {
            return ['queries' => 0, 'records' => 0, 'complete' => true, 'retryAfter' => null];
        }

        $now = CarbonImmutable::now();
        $fresh = $now->subHours(max(1, config()->integer('launchroom.security.osv_refresh_hours', 6)));
        $feed->forceFill(['last_attempt_at' => $now])->save();
        $cursor = ! $only instanceof ImageInventory && ! $force && is_array($feed->cursor['after'] ?? null) ? $feed->cursor['after'] : null;
        $asked = 0;
        $records = 0;

        try {
            while (true) {
                $batch = $this->pending($only, $force ? $now : $fresh, $cursor);

                if ($batch === []) {
                    break;
                }

                $results = $this->ask($batch);
                $records += $this->store($results, $now);
                $asked += count($batch);
                $last = $batch[count($batch) - 1];
                $cursor = [$last['ecosystem'], $last['name'], $last['version']];

                if (! $only instanceof ImageInventory) {
                    $feed->forceFill(['cursor' => ['after' => $cursor]])->save();
                }
            }
        } catch (FeedUnavailable $e) {
            $backoff = $e->retryAfterSeconds ?? min(1800, max(30, $feed->backoff_seconds * 2));
            $feed->forceFill([
                'last_error' => mb_substr($e->getMessage(), 0, 512),
                'failing_since' => $feed->failing_since ?? $now,
                'backoff_seconds' => min(1800, $backoff),
                'retry_at' => $now->addSeconds(min(1800, $backoff)),
            ])->save();
            Log::warning('osv.dev sync paused.', ['error' => $e->getMessage(), 'retryIn' => $backoff]);
            $this->evaluate($only);

            return ['queries' => $asked, 'records' => $records, 'complete' => false, 'retryAfter' => min(1800, $backoff)];
        }

        $feed->forceFill([
            'last_success_at' => $now,
            'last_error' => null,
            'failing_since' => null,
            'backoff_seconds' => 0,
            'retry_at' => null,
            'items' => DB::table('advisories')->count(),
            ...($only instanceof ImageInventory ? [] : ['cursor' => null]),
        ])->save();

        if ($only instanceof ImageInventory) {
            $only->forceFill(['checked_at' => $now])->save();
        } else {
            DB::table('image_inventories')->where('status', 'ready')->update(['checked_at' => $now]);
        }

        $this->evaluate($only);

        return ['queries' => $asked, 'records' => $records, 'complete' => true, 'retryAfter' => null];
    }

    /**
     * The next batch of OSV packages to ask, in key order after the cursor.
     *
     * @param  list<string>|null  $after
     * @return list<Query>
     */
    private function pending(?ImageInventory $only, CarbonImmutable $fresh, ?array $after): array
    {
        $query = DB::table('inventory_components as c')
            ->join('image_inventories as i', 'i.id', '=', 'c.inventory_id')
            ->leftJoin('advisory_queries as q', static function (JoinClause $join): void {
                $join->on('q.osv_ecosystem', '=', 'c.osv_ecosystem')->on('q.osv_name', '=', 'c.osv_name')->on('q.version', '=', 'c.osv_version');
            })
            ->whereNotNull('c.osv_ecosystem')
            ->where('i.status', 'ready')
            ->where(static fn (Builder $q) => $q->whereNull('q.checked_at')->orWhere('q.checked_at', '<', $fresh->toIso8601String()))
            ->when($only instanceof ImageInventory, static fn (Builder $q) => $q->where('c.inventory_id', $only?->id))
            ->when($after !== null && count($after) === 3, static fn (Builder $q) => $q->whereRaw('(c.osv_ecosystem, c.osv_name, c.osv_version) > (?, ?, ?)', $after))
            ->select(['c.osv_ecosystem', 'c.osv_name', 'c.osv_version'])
            ->distinct()
            ->orderBy('c.osv_ecosystem')
            ->orderBy('c.osv_name')
            ->orderBy('c.osv_version')
            ->limit(self::BATCH);

        return array_values(array_map(static fn (object $row): array => [
            'ecosystem' => Cast::string($row->osv_ecosystem ?? null),
            'name' => Cast::string($row->osv_name ?? null),
            'version' => Cast::string($row->osv_version ?? null),
        ], $query->get()->all()));
    }

    /**
     * Asks one batch (following result pages); a batch refused with a 4xx
     * is split in halves until the refused query is alone.
     *
     * @param  list<Query>  $batch
     * @return list<array{query: Query, ids: array<string, string|null>, error: string|null}>
     *
     * @throws FeedUnavailable
     */
    private function ask(array $batch): array
    {
        try {
            $results = $this->osv->queryBatch($batch);
        } catch (FeedRejected $e) {
            if (count($batch) === 1) {
                return [['query' => $batch[0], 'ids' => [], 'error' => mb_substr($e->getMessage(), 0, 512)]];
            }

            $half = intdiv(count($batch), 2);

            return [...$this->ask(array_slice($batch, 0, $half)), ...$this->ask(array_slice($batch, $half))];
        }

        /** @var array<int, array<string, string|null>> $ids */
        $ids = [];
        $more = [];

        foreach ($results as $i => $result) {
            $ids[$i] = [];

            foreach ($result['vulns'] as $vuln) {
                $ids[$i][$vuln['id']] = $vuln['modified'];
            }

            if ($result['nextPageToken'] !== null) {
                $more[$i] = $result['nextPageToken'];
            }
        }

        for ($page = 0; $more !== [] && $page < self::MAX_PAGES; $page++) {
            $indexes = array_keys($more);
            $pages = $this->osv->queryBatch(array_map(static fn (int $i): array => [...$batch[$i], 'pageToken' => $more[$i]], $indexes));
            $more = [];

            foreach ($pages as $j => $result) {
                $i = $indexes[$j];

                foreach ($result['vulns'] as $vuln) {
                    $ids[$i][$vuln['id']] = $vuln['modified'];
                }

                if ($result['nextPageToken'] !== null) {
                    $more[$i] = $result['nextPageToken'];
                }
            }
        }

        $out = [];

        foreach ($batch as $i => $query) {
            $out[] = ['query' => $query, 'ids' => $ids[$i] ?? [], 'error' => null];
        }

        return $out;
    }

    /**
     * Stores the answers and fetches records that are new or modified.
     *
     * @param  list<array{query: Query, ids: array<string, string|null>, error: string|null}>  $results
     *
     * @throws FeedUnavailable
     */
    private function store(array $results, CarbonImmutable $now): int
    {
        $ids = [];

        foreach ($results as $result) {
            foreach ($result['ids'] as $id => $modified) {
                $ids[$id] = $modified;
            }
        }

        $known = [];

        foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
            foreach (DB::table('advisories')->whereIn('id', $chunk)->get(['id', 'modified']) as $row) {
                $known[Cast::string($row->id ?? null)] = is_string($row->modified ?? null) ? CarbonImmutable::parse($row->modified) : null;
            }
        }

        $fetched = 0;

        foreach ($ids as $id => $modified) {
            $stored = $known[$id] ?? false;
            $newer = $modified !== null && $stored instanceof CarbonImmutable && CarbonImmutable::parse($modified)->greaterThan($stored);

            if ($stored !== false && ! $newer) {
                continue;
            }

            try {
                $raw = $this->osv->vulnerability($id);
            } catch (FeedRejected $e) {
                Log::warning('Dropped an OSV record.', ['id' => $id, 'error' => $e->getMessage()]);

                continue;
            }

            $record = $raw === null ? null : OsvRecord::parse($raw);

            if ($record === null) {
                continue;
            }

            $this->saveRecord($record, $now);
            $fetched++;
        }

        $rows = array_map(static fn (array $result): array => [
            'osv_ecosystem' => $result['query']['ecosystem'],
            'osv_name' => $result['query']['name'],
            'version' => $result['query']['version'],
            'advisory_ids' => json_encode(array_keys($result['ids']), JSON_THROW_ON_ERROR),
            'modified' => json_encode((object) array_filter($result['ids'], is_string(...)), JSON_THROW_ON_ERROR),
            'checked_at' => $now->toIso8601String(),
            'error' => $result['error'],
        ], $results);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('advisory_queries')->upsert($chunk, ['osv_ecosystem', 'osv_name', 'version'], ['advisory_ids', 'modified', 'checked_at', 'error']);
        }

        foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
            DB::table('advisories')->whereIn('id', $chunk)->update(['matched_at' => $now->toIso8601String()]);
        }

        return $fetched;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function saveRecord(array $record, CarbonImmutable $now): void
    {
        $row = [...$record, 'fetched_at' => $now->toIso8601String(), 'matched_at' => $now->toIso8601String()];

        foreach (['aliases', 'upstream', 'cve_ids', 'cwe_ids', 'affected', 'references'] as $json) {
            $row[$json] = json_encode($record[$json], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        $row['cvss'] = json_encode((object) $record['cvss'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        DB::table('advisories')->upsert([$row], ['id'], array_keys(array_diff_key($row, ['id' => true])));
    }

    private function evaluate(?ImageInventory $only): void
    {
        $teams = $only instanceof ImageInventory
            ? [$only->team_id]
            : array_map(Cast::id(...), DB::table('image_inventories')->where('status', 'ready')->distinct()->pluck('team_id')->all());

        foreach ($teams as $team) {
            dispatch(new EvaluateSecurityFindingsJob($team));
        }
    }
}
