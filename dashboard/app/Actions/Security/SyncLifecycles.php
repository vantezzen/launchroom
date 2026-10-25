<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Enums\Security\AdvisoryFeed;
use App\Models\Security\AdvisoryFeedState;
use App\Services\Security\EndOfLifeClient;
use App\Services\Security\FeedRejected;
use App\Services\Security\FeedUnavailable;
use App\Support\Metrics\Cast;
use App\Support\Security\LifecycleProducts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * endoflife.date (docs/22 §4.6): daily, one request per product in use:
 * runtimes from inventories, distributions from their os-release and
 * database engines from the services. Release cycles are upserted per
 * product; a product whose answer fails is skipped and keeps its rows.
 */
final readonly class SyncLifecycles
{
    public function __construct(
        private EndOfLifeClient $eol,
    ) {}

    /**
     * @return array{products: int, failed: int}
     */
    public function handle(): array
    {
        $feed = AdvisoryFeedState::of(AdvisoryFeed::Eol);

        if (! $feed->enabled) {
            return ['products' => 0, 'failed' => 0];
        }

        $now = CarbonImmutable::now();
        $feed->forceFill(['last_attempt_at' => $now])->save();
        $done = 0;
        $error = null;

        foreach ($this->productsInUse() as $product) {
            try {
                $releases = $this->eol->product($product);
            } catch (FeedUnavailable|FeedRejected $e) {
                $error = mb_substr($e->getMessage(), 0, 512);

                continue;
            }

            $rows = array_map(static fn (array $release): array => [
                'product' => $product,
                ...$release,
                'fetched_at' => $now->toIso8601String(),
            ], $releases);

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('lifecycle_releases')->upsert($chunk, ['product', 'cycle'], ['latest', 'latest_date', 'release_date', 'eol', 'is_eol', 'fetched_at']);
            }

            $done++;
        }

        $feed->forceFill($error === null
            ? ['last_success_at' => $now, 'last_error' => null, 'failing_since' => null, 'items' => DB::table('lifecycle_releases')->count()]
            : ['last_error' => $error, 'failing_since' => $feed->failing_since ?? $now, ...($done > 0 ? ['last_success_at' => $now] : [])])->save();

        return ['products' => $done, 'failed' => $error === null ? 0 : 1];
    }

    /**
     * @return list<string>
     */
    private function productsInUse(): array
    {
        $products = [];

        $runtimes = DB::table('inventory_components as c')
            ->join('image_inventories as i', 'i.id', '=', 'c.inventory_id')
            ->where('i.status', 'ready')
            ->where('c.kind', 'runtime')
            ->distinct()
            ->get(['c.ecosystem', 'c.name']);

        foreach ($runtimes as $row) {
            $product = LifecycleProducts::forRuntime(Cast::string($row->ecosystem ?? null), Cast::string($row->name ?? null));

            if ($product !== null) {
                $products[$product] = true;
            }
        }

        foreach (DB::table('image_inventories')->where('status', 'ready')->whereNotNull('distro')->pluck('distro') as $distro) {
            $decoded = is_string($distro) ? json_decode($distro, true) : null;
            $product = is_array($decoded) && is_string($decoded['id'] ?? null) ? LifecycleProducts::forDistro($decoded['id']) : null;

            if ($product !== null) {
                $products[$product] = true;
            }
        }

        foreach (DB::table('services')->where('kind', 'database')->pluck('source') as $source) {
            $decoded = is_string($source) ? json_decode($source, true) : null;
            $product = is_array($decoded) && is_string($decoded['engine'] ?? null) ? LifecycleProducts::forEngine($decoded['engine']) : null;

            if ($product !== null) {
                $products[$product] = true;
            }
        }

        $list = array_keys($products);
        sort($list);

        return $list;
    }
}
