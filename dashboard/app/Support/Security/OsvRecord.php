<?php

declare(strict_types=1);

namespace App\Support\Security;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads one OSV vulnerability record into the fields launchroom keeps
 * (docs/22 §4.3): ids, dates, summary (≤ 512 characters), details
 * (≤ 16 KiB), the GHSA severity label, CVSS vectors and the v3 score,
 * CWE ids, the fixed versions of each affected package and up to 20 https
 * references. Text stays text: it is rendered without HTML. Null when the
 * record fails its grammar (dropped, docs/22 §4.4). Pure.
 */
final class OsvRecord
{
    private const string ID = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D';

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array{id: string, group_key: string, aliases: list<string>, upstream: list<string>, cve_ids: list<string>, modified: string|null, published: string|null, withdrawn: string|null, summary: string|null, details: string|null, severity_label: string|null, cvss: array{v3?: string, v3Score?: float, v4?: string}, attack_vector: string|null, cwe_ids: list<string>, affected: list<array{ecosystem: string, name: string, fixed: list<string>}>, references: list<array{type: string, url: string}>}|null
     */
    public static function parse(array $raw): ?array
    {
        $id = $raw['id'] ?? null;

        if (! is_string($id) || preg_match(self::ID, $id) !== 1) {
            return null;
        }

        $aliases = self::ids($raw['aliases'] ?? []);
        $upstream = self::ids($raw['upstream'] ?? []);
        $cvss = [];

        // Top-level severity, and per affected package where databases put it
        // (Bitnami's runtime records carry their CVSS only there).
        $severities = is_array($raw['severity'] ?? null) ? $raw['severity'] : [];

        foreach (is_array($raw['affected'] ?? null) ? array_slice($raw['affected'], 0, 50) : [] as $entry) {
            if (is_array($entry) && is_array($entry['severity'] ?? null)) {
                $severities = [...$severities, ...array_slice($entry['severity'], 0, 4)];
            }
        }

        foreach ($severities as $severity) {
            $type = is_array($severity) ? ($severity['type'] ?? null) : null;
            $score = is_array($severity) ? ($severity['score'] ?? null) : null;

            if (! is_string($score) || strlen($score) > 200) {
                continue;
            }

            if ($type === 'CVSS_V3' && Cvss3::metrics($score) !== null) {
                $value = Cvss3::baseScore($score);

                if (! isset($cvss['v3Score']) || ($value ?? 0) > $cvss['v3Score']) {
                    $cvss['v3'] = $score;
                    $cvss['v3Score'] = $value ?? 0.0;
                }
            } elseif ($type === 'CVSS_V4' && preg_match('#^CVSS:4\.0/[A-Za-z:/]{1,190}$#D', $score) === 1) {
                $cvss['v4'] = $score;
            }
        }

        $database = is_array($raw['database_specific'] ?? null) ? $raw['database_specific'] : [];
        $label = $database['severity'] ?? null;
        $label = is_string($label) && in_array(strtoupper($label), ['LOW', 'MODERATE', 'MEDIUM', 'HIGH', 'CRITICAL'], true) ? strtoupper($label) : null;
        $cwes = [];

        foreach (is_array($database['cwe_ids'] ?? null) ? $database['cwe_ids'] : [] as $cwe) {
            if (is_string($cwe) && preg_match('/^CWE-\d{1,5}$/D', $cwe) === 1) {
                $cwes[] = $cwe;
            }
        }

        return [
            'id' => $id,
            'group_key' => AdvisoryGroups::key($id, $aliases, $upstream),
            'aliases' => $aliases,
            'upstream' => $upstream,
            'cve_ids' => AdvisoryGroups::cves([$id, ...$aliases, ...$upstream]),
            'modified' => self::time($raw['modified'] ?? null),
            'published' => self::time($raw['published'] ?? null),
            'withdrawn' => self::time($raw['withdrawn'] ?? null),
            'summary' => self::text($raw['summary'] ?? null, 512, true) ?? self::firstSentence(self::text($raw['details'] ?? null, 2048, true)),
            'details' => self::text($raw['details'] ?? null, 16 * 1024, false),
            'severity_label' => $label,
            'cvss' => $cvss,
            'attack_vector' => isset($cvss['v3']) ? Cvss3::attackVector($cvss['v3']) : (isset($cvss['v4']) && preg_match('#/AV:([NALP])(?:/|$)#', $cvss['v4'], $m) === 1 ? $m[1] : null),
            'cwe_ids' => array_values(array_unique(array_slice($cwes, 0, 20))),
            'affected' => self::affected($raw['affected'] ?? null),
            'references' => self::references($raw['references'] ?? null),
        ];
    }

    /**
     * @return list<string>
     */
    private static function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach (array_slice($value, 0, 64) as $id) {
            if (is_string($id) && preg_match(self::ID, $id) === 1) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<array{ecosystem: string, name: string, fixed: list<string>}>
     */
    private static function affected(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach (array_slice($value, 0, 50) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $package = is_array($entry['package'] ?? null) ? $entry['package'] : null;
            $ecosystem = $package['ecosystem'] ?? null;
            $name = $package['name'] ?? null;

            if (! is_string($ecosystem) || ! is_string($name) || strlen($ecosystem) > 64 || strlen($name) > 256 || preg_match('/[\x00-\x1f\x7f]/', $ecosystem.$name) === 1) {
                continue;
            }

            $fixed = [];

            foreach (is_array($entry['ranges'] ?? null) ? $entry['ranges'] : [] as $range) {
                foreach (is_array($range) && is_array($range['events'] ?? null) ? $range['events'] : [] as $event) {
                    $version = is_array($event) ? ($event['fixed'] ?? null) : null;

                    if (is_string($version) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._+~:^-]{0,127}$/D', $version) === 1) {
                        $fixed[] = $version;
                    }
                }
            }

            $out[] = ['ecosystem' => $ecosystem, 'name' => $name, 'fixed' => array_values(array_unique(array_slice($fixed, 0, 20)))];
        }

        return $out;
    }

    /**
     * @return list<array{type: string, url: string}>
     */
    private static function references(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $reference) {
            $url = is_array($reference) ? ($reference['url'] ?? null) : null;
            $type = is_array($reference) ? ($reference['type'] ?? null) : null;

            // https only, no credentials, no whitespace (docs/22 §13).
            if (! is_string($url) || strlen($url) > 2048 || preg_match('#^https://[^\s/@]+(?:/[^\s]*)?$#D', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }

            $out[] = ['type' => is_string($type) && preg_match('/^[A-Z_]{1,32}$/D', $type) === 1 ? $type : 'WEB', 'url' => $url];

            if (count($out) === 20) {
                break;
            }
        }

        return $out;
    }

    /**
     * A summary for records without one (Bitnami's): the first sentence of
     * the details, at most 200 characters.
     */
    private static function firstSentence(?string $details): ?string
    {
        if ($details === null) {
            return null;
        }

        $sentence = preg_match('/^(.+?[.!?])(?:\s|$)/u', $details, $m) === 1 ? $m[1] : $details;

        return mb_strlen($sentence) > 200 ? rtrim(mb_substr($sentence, 0, 199)).'…' : $sentence;
    }

    private static function text(mixed $value, int $max, bool $oneLine): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $clean = $oneLine
            ? trim((string) preg_replace('/\s+/u', ' ', $value))
            : trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $value));

        if ($clean === '') {
            return null;
        }

        return $oneLine ? mb_substr($clean, 0, $max) : mb_strcut($clean, 0, $max);
    }

    private static function time(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 40) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc()->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
