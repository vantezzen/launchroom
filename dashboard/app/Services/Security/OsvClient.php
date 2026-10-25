<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * osv.dev (docs/22 §4.1): `POST /v1/querybatch` with up to 1,000 package
 * queries and `GET /v1/vulns/{id}` for full records. Only ecosystem, name
 * and version leave the instance (§4.2).
 */
final readonly class OsvClient
{
    public const string BATCH_URL = 'https://api.osv.dev/v1/querybatch';

    public const string VULN_URL = 'https://api.osv.dev/v1/vulns/';

    private const string ID = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D';

    public function __construct(
        private FeedHttp $http,
    ) {}

    /**
     * @param  list<array{ecosystem: string, name: string, version: string, pageToken?: string}>  $queries
     * @return list<array{vulns: list<array{id: string, modified: string|null}>, nextPageToken: string|null}> one result per query, in order
     *
     * @throws FeedUnavailable
     * @throws FeedRejected
     */
    public function queryBatch(array $queries): array
    {
        $body = ['queries' => array_map(static fn (array $q): array => [
            'package' => ['ecosystem' => $q['ecosystem'], 'name' => $q['name']],
            'version' => $q['version'],
            ...(isset($q['pageToken']) ? ['page_token' => $q['pageToken']] : []),
        ], $queries)];

        $decoded = $this->http->send('POST', self::BATCH_URL, 8 * 1024 * 1024, $body)->json();
        $results = is_array($decoded) ? ($decoded['results'] ?? null) : null;

        if (! is_array($results) || ! array_is_list($results) || count($results) !== count($queries)) {
            throw new FeedRejected('osv.dev answered with an unexpected document.');
        }

        $out = [];

        foreach ($results as $result) {
            $vulns = [];

            foreach (is_array($result) && is_array($result['vulns'] ?? null) ? $result['vulns'] : [] as $vuln) {
                $id = is_array($vuln) ? ($vuln['id'] ?? null) : null;

                // A record id outside the grammar is dropped (docs/22 §4.4).
                if (is_string($id) && preg_match(self::ID, $id) === 1) {
                    $modified = $vuln['modified'] ?? null;
                    $vulns[] = ['id' => $id, 'modified' => is_string($modified) && strlen($modified) <= 40 ? $modified : null];
                }
            }

            $token = is_array($result) ? ($result['next_page_token'] ?? null) : null;
            $out[] = ['vulns' => $vulns, 'nextPageToken' => is_string($token) && $token !== '' && strlen($token) <= 1024 ? $token : null];
        }

        return $out;
    }

    /**
     * The full record, or null when osv.dev doesn't know the id (anymore).
     *
     * @return array<array-key, mixed>|null
     *
     * @throws FeedUnavailable
     * @throws FeedRejected
     */
    public function vulnerability(string $id): ?array
    {
        if (preg_match(self::ID, $id) !== 1) {
            return null;
        }

        try {
            $decoded = $this->http->send('GET', self::VULN_URL.rawurlencode($id), 1024 * 1024)->json();
        } catch (FeedRejected $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }

            throw $e;
        }

        if (! is_array($decoded) || ($decoded['id'] ?? null) !== $id) {
            throw new FeedRejected("osv.dev answered with an unexpected record for {$id}.");
        }

        return $decoded;
    }
}
