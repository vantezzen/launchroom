<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * CISA's Known Exploited Vulnerabilities catalogue (docs/22 §4.1): one
 * public JSON file, fetched with a conditional GET. A document that fails
 * its grammar is refused as a whole and the stored catalogue stays.
 */
final readonly class KevClient
{
    public const string URL = 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json';

    public function __construct(
        private FeedHttp $http,
    ) {}

    /**
     * @return array{changed: false}|array{changed: true, etag: string|null, lastModified: string|null, entries: list<array{cve_id: string, date_added: string, due_date: string|null, ransomware: bool, vendor: string|null, product: string|null, name: string|null}>}
     *
     * @throws FeedUnavailable
     * @throws FeedRejected
     */
    public function fetch(?string $etag, ?string $lastModified): array
    {
        $headers = array_filter([
            'If-None-Match' => $etag,
            'If-Modified-Since' => $lastModified,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        $response = $this->http->send('GET', self::URL, 16 * 1024 * 1024, null, $headers);

        if ($response->status() === 304) {
            return ['changed' => false];
        }

        $decoded = $response->json();
        $items = is_array($decoded) ? ($decoded['vulnerabilities'] ?? null) : null;

        if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > 100_000) {
            throw new FeedRejected('The KEV catalogue had an unexpected shape.');
        }

        $entries = [];

        foreach ($items as $item) {
            $cve = is_array($item) ? ($item['cveID'] ?? null) : null;
            $added = is_array($item) ? ($item['dateAdded'] ?? null) : null;
            $due = is_array($item) ? ($item['dueDate'] ?? null) : null;

            if (! is_array($item) || ! is_string($cve) || preg_match('/^CVE-\d{4}-\d{4,}$/D', $cve) !== 1
                || ! is_string($added) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $added) !== 1
                || ($due !== null && (! is_string($due) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $due) !== 1))) {
                throw new FeedRejected('The KEV catalogue has an entry launchroom can\'t read.');
            }

            $entries[$cve] = [
                'cve_id' => $cve,
                'date_added' => $added,
                'due_date' => $due,
                'ransomware' => ($item['knownRansomwareCampaignUse'] ?? null) === 'Known',
                'vendor' => $this->text($item['vendorProject'] ?? null, 128),
                'product' => $this->text($item['product'] ?? null, 128),
                'name' => $this->text($item['vulnerabilityName'] ?? null, 256),
            ];
        }

        $etagHeader = $response->header('ETag');
        $lastModifiedHeader = $response->header('Last-Modified');

        return [
            'changed' => true,
            'etag' => $etagHeader !== '' && strlen($etagHeader) <= 256 ? $etagHeader : null,
            'lastModified' => $lastModifiedHeader !== '' && strlen($lastModifiedHeader) <= 64 ? $lastModifiedHeader : null,
            'entries' => array_values($entries),
        ];
    }

    private function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $clean = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value));

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }
}
