<?php

declare(strict_types=1);

use App\Exceptions\DomainRuleViolation;
use App\Support\S3\S3Location;

test('a location is normalised', function (): void {
    $location = S3Location::parse(' HTTPS://S3.Example.com/ ', 'my-bucket', ' EU-Central-1 ', '/backups/team/');

    expect($location->endpoint)->toBe('https://s3.example.com')
        ->and($location->region)->toBe('eu-central-1')
        ->and($location->prefix)->toBe('backups/team')
        ->and($location->host())->toBe('s3.example.com')
        ->and(S3Location::parse('https://s3.example.com', 'abc', '', '')->region)->toBeNull()
        ->and(S3Location::regionOrDefault(null))->toBe('us-east-1');
});

test('malformed endpoints, buckets, prefixes and regions are refused on their field', function (string $endpoint, string $bucket, ?string $region, string $prefix, string $field): void {
    try {
        S3Location::parse($endpoint, $bucket, $region, $prefix);
        $this->fail('accepted');
    } catch (DomainRuleViolation $e) {
        expect($e->field)->toBe($field);
    }
})->with([
    'endpoint with a path' => ['https://s3.example.com/bucket', 'abc', null, '', 'endpoint'],
    'endpoint with credentials' => ['https://k:s@s3.example.com', 'abc', null, '', 'endpoint'],
    'short bucket' => ['https://s3.example.com', 'ab', null, '', 'bucket'],
    'bucket with ..' => ['https://s3.example.com', 'a..b', null, '', 'bucket'],
    'prefix over 200' => ['https://s3.example.com', 'abc', null, str_repeat('a', 201), 'prefix'],
    'prefix with ..' => ['https://s3.example.com', 'abc', null, 'a..b', 'prefix'],
    'prefix with a . segment' => ['https://s3.example.com', 'abc', null, 'a/./b', 'prefix'],
    'prefix with //' => ['https://s3.example.com', 'abc', null, 'a//b', 'prefix'],
    'region' => ['https://s3.example.com', 'abc', 'eu_central', '', 'region'],
]);
