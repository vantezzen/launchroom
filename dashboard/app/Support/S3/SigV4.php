<?php

declare(strict_types=1);

namespace App\Support\S3;

use DateTimeInterface;
use SensitiveParameter;

/**
 * AWS Signature Version 4 for S3 requests (path-style). Small on purpose:
 * one region, service `s3`, the payload hash given by the caller.
 * https://docs.aws.amazon.com/AmazonS3/latest/API/sig-v4-header-based-auth.html
 */
final class SigV4
{
    public const string ALGORITHM = 'AWS4-HMAC-SHA256';

    /**
     * The headers to send: the given ones plus `x-amz-date`,
     * `x-amz-content-sha256` and `Authorization`. `$host` is the Host header
     * value (with the port when it isn't the scheme's default).
     *
     * @param  array<string, string>  $query  unencoded
     * @param  array<string, string>  $headers  unencoded
     * @return array<string, string>
     */
    public static function sign(
        string $method,
        string $host,
        string $path,
        array $query,
        array $headers,
        string $payloadHash,
        string $region,
        #[SensitiveParameter] string $accessKeyId,
        #[SensitiveParameter] string $secretAccessKey,
        DateTimeInterface $now,
    ): array {
        $amzDate = gmdate('Ymd\THis\Z', $now->getTimestamp());
        $date = substr($amzDate, 0, 8);
        $scope = "{$date}/{$region}/s3/aws4_request";

        $signed = ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate];

        foreach ($headers as $name => $value) {
            $signed[strtolower($name)] = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        }

        ksort($signed, SORT_STRING);
        $canonicalHeaders = '';

        foreach ($signed as $name => $value) {
            $canonicalHeaders .= "{$name}:{$value}\n";
        }

        $signedHeaders = implode(';', array_keys($signed));

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            self::canonicalPath($path),
            self::canonicalQuery($query),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $stringToSign = implode("\n", [self::ALGORITHM, $amzDate, $scope, hash('sha256', $canonicalRequest)]);

        $key = hash_hmac('sha256', $date, 'AWS4'.$secretAccessKey, true);
        $key = hash_hmac('sha256', $region, $key, true);
        $key = hash_hmac('sha256', 's3', $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);
        $signature = hash_hmac('sha256', $stringToSign, $key);

        $out = $headers;
        $out['x-amz-date'] = $amzDate;
        $out['x-amz-content-sha256'] = $payloadHash;
        $out['Authorization'] = self::ALGORITHM." Credential={$accessKeyId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        return $out;
    }

    public static function canonicalPath(string $path): string
    {
        $segments = array_map(rawurlencode(...), explode('/', $path));

        return implode('/', $segments);
    }

    /**
     * @param  array<string, string>  $query
     */
    public static function canonicalQuery(array $query): string
    {
        $pairs = [];

        foreach ($query as $key => $value) {
            $pairs[rawurlencode($key)] = rawurlencode($value);
        }

        ksort($pairs, SORT_STRING);

        return implode('&', array_map(static fn (string $key, string $value): string => "{$key}={$value}", array_keys($pairs), $pairs));
    }
}
