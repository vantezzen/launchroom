<?php

declare(strict_types=1);

namespace App\Support\S3;

use App\Exceptions\DomainRuleViolation;
use App\Support\Protocol\Grammar;

/**
 * Where an S3-compatible bucket lives: backup destinations (docs/14 §3), the
 * registry's storage (docs/14 §9.11) and audit sinks (docs/09 R34) share one
 * grammar. The endpoint grammar is launchroomd's `S3Endpoint` (protocol
 * schema): scheme, host and port only. Reachability (docs/09 R5) is checked
 * separately, by the caller's address guard.
 */
final readonly class S3Location
{
    /** The region S3 clients assume when none is configured. */
    public const string DEFAULT_REGION = 'us-east-1';

    public const string ENDPOINT_PATTERN = Grammar::S3_ENDPOINT;

    public const string BUCKET_PATTERN = Grammar::BUCKET;

    public const string PREFIX_PATTERN = Grammar::OBJECT_PREFIX;

    public const string REGION_PATTERN = Grammar::REGION;

    public const int PREFIX_MAX = 200;

    private function __construct(
        public string $endpoint,
        public string $bucket,
        public ?string $region,
        public string $prefix,
    ) {}

    /**
     * Normalises (endpoint lowercase without a trailing slash, region
     * lowercase or null, prefix without outer slashes) and checks the
     * grammar.
     *
     * @throws DomainRuleViolation naming the offending field
     */
    public static function parse(string $endpoint, string $bucket, ?string $region, ?string $prefix): self
    {
        $endpoint = self::endpoint($endpoint);
        $region = strtolower(trim((string) $region));
        $region = $region === '' ? null : $region;
        $prefix = trim((string) $prefix, '/');

        if (preg_match(self::BUCKET_PATTERN, $bucket) !== 1 || str_contains($bucket, '..')) {
            throw DomainRuleViolation::on('bucket', 'Bucket names are 3–63 lowercase letters, digits, dots and dashes.');
        }

        $segments = $prefix === '' ? [] : explode('/', $prefix);

        if (strlen($prefix) > self::PREFIX_MAX || preg_match(self::PREFIX_PATTERN, $prefix) !== 1 || str_contains($prefix, '..') || in_array('.', $segments, true)) {
            throw DomainRuleViolation::on('prefix', 'The prefix may contain letters, digits, dots, dashes, underscores and slashes, without . or .. segments.');
        }

        if ($region !== null && preg_match(self::REGION_PATTERN, $region) !== 1) {
            throw DomainRuleViolation::on('region', 'Regions are lowercase letters, digits and dashes (for example auto, fsn1 or us-east-1).');
        }

        return new self($endpoint, $bucket, $region, $prefix);
    }

    /**
     * A normalised endpoint (`scheme://host[:port]`).
     *
     * @throws DomainRuleViolation
     */
    public static function endpoint(string $endpoint): string
    {
        $endpoint = strtolower(rtrim(trim($endpoint), '/'));

        if (preg_match(self::ENDPOINT_PATTERN, $endpoint) !== 1) {
            throw DomainRuleViolation::on('endpoint', 'Enter the endpoint as https://host[:port], without a bucket, path or credentials.');
        }

        return $endpoint;
    }

    public static function regionOrDefault(?string $region): string
    {
        return $region === null || $region === '' ? self::DEFAULT_REGION : $region;
    }

    public function host(): string
    {
        return (string) parse_url($this->endpoint, PHP_URL_HOST);
    }
}
