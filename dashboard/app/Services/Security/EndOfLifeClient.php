<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Support\Security\LifecycleProducts;

/**
 * endoflife.date (docs/22 §4.1, §4.6): release cycles, latest patch and
 * end of life of one product per request (`/api/v1/products/{product}`,
 * products from a fixed table only). A document that fails its grammar is
 * refused as a whole.
 */
final readonly class EndOfLifeClient
{
    public const string URL = 'https://endoflife.date/api/v1/products/';

    private const string DATE = '/^\d{4}-\d{2}-\d{2}$/D';

    public function __construct(
        private FeedHttp $http,
    ) {}

    /**
     * @return list<array{cycle: string, latest: string|null, latest_date: string|null, release_date: string|null, eol: string|null, is_eol: bool}>
     *
     * @throws FeedUnavailable
     * @throws FeedRejected
     */
    public function product(string $product): array
    {
        if (! in_array($product, LifecycleProducts::all(), true)) {
            throw new FeedRejected("{$product} is not a tracked product.");
        }

        $decoded = $this->http->send('GET', self::URL.$product, 1024 * 1024)->json();
        $releases = is_array($decoded) && is_array($decoded['result'] ?? null) ? ($decoded['result']['releases'] ?? null) : null;

        if (! is_array($releases) || ! array_is_list($releases) || count($releases) > 1000) {
            throw new FeedRejected("endoflife.date answered with an unexpected document for {$product}.");
        }

        $out = [];

        foreach ($releases as $release) {
            $cycle = is_array($release) ? ($release['name'] ?? null) : null;

            if (! is_array($release) || ! is_string($cycle) || preg_match('/^[A-Za-z0-9._-]{1,32}$/D', $cycle) !== 1) {
                throw new FeedRejected("endoflife.date has a release of {$product} launchroom can't read.");
            }

            $latest = is_array($release['latest'] ?? null) ? $release['latest'] : [];
            $latestName = $latest['name'] ?? null;
            $eol = $release['eolFrom'] ?? null;
            $isEol = $release['isEol'] ?? false;

            $out[] = [
                'cycle' => $cycle,
                'latest' => is_string($latestName) && preg_match('/^[A-Za-z0-9._+-]{1,64}$/D', $latestName) === 1 ? $latestName : null,
                'latest_date' => $this->date($latest['date'] ?? null),
                'release_date' => $this->date($release['releaseDate'] ?? null),
                'eol' => $this->date($eol),
                'is_eol' => $isEol === true,
            ];
        }

        return $out;
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::DATE, $value) === 1 ? $value : null;
    }
}
