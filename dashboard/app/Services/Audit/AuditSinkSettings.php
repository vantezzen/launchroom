<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Data\Audit\AuditSinkInput;
use App\Enums\Audit\AuditSinkKind;
use App\Exceptions\DomainRuleViolation;
use App\Services\Http\Exceptions\UnsafeUrl;
use App\Services\Http\UrlGuard;
use App\Support\Hostname;

/**
 * Turns the add-destination form into a sink's settings (docs/09 R34):
 * grammar first, then the R5 address check (resolved once; public
 * addresses only unless the owner allows the private network for this
 * sink), so a refused destination is never stored.
 */
final readonly class AuditSinkSettings
{
    public function __construct(
        private UrlGuard $guard,
    ) {}

    /**
     * @return array{config: array<string, mixed>, secret: array<string, string>|null, hint: string|null}
     */
    public function from(AuditSinkInput $input): array
    {
        $guard = $input->allowPrivateNetwork ? $this->guard->withPrivateNetwork() : $this->guard;

        return match ($input->kind) {
            AuditSinkKind::Syslog => $this->syslog($input, $guard),
            AuditSinkKind::Webhook => $this->webhook($input, $guard),
            AuditSinkKind::S3 => $this->s3($input, $guard),
        };
    }

    /**
     * @return array{config: array<string, mixed>, secret: null, hint: string}
     */
    private function syslog(AuditSinkInput $input, UrlGuard $guard): array
    {
        $host = Hostname::normalize((string) $input->host);

        if (! Hostname::isValid($host) && filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw DomainRuleViolation::on('host', 'Enter the receiver’s host name, e.g. logs.example.com.');
        }

        $port = $input->port ?? 6514;
        $this->vet($guard, 'https://'.(str_contains($host, ':') ? "[{$host}]" : $host).':'.$port.'/', 'host', $input->allowPrivateNetwork);

        $config = ['host' => $host, 'port' => $port];
        $ca = trim((string) $input->caCertificate);

        if ($ca !== '') {
            if (preg_match('/\A-----BEGIN CERTIFICATE-----\s[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----\z/', $ca) !== 1 || openssl_x509_read($ca) === false) {
                throw DomainRuleViolation::on('caCertificate', 'Paste one PEM certificate (-----BEGIN CERTIFICATE----- …).');
            }

            $config['caCertificate'] = $ca."\n";
        }

        return ['config' => $config, 'secret' => null, 'hint' => "{$host}:{$port}"];
    }

    /**
     * @return array{config: array<string, mixed>, secret: array<string, string>, hint: string}
     */
    private function webhook(AuditSinkInput $input, UrlGuard $guard): array
    {
        $url = trim((string) $input->secretUrl);
        $parts = parse_url($url);
        $schemes = $input->allowPrivateNetwork ? ['https', 'http'] : ['https'];

        if ($url === '' || ! is_array($parts) || ! in_array($parts['scheme'] ?? '', $schemes, true) || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw DomainRuleViolation::on('secretUrl', 'Enter an https:// address without a user name or #fragment.');
        }

        $this->vet($guard, $url, 'secretUrl', $input->allowPrivateNetwork);

        return [
            'config' => [],
            'secret' => ['url' => $url, 'signingSecret' => AuditSinkSecrets::generateSigningSecret()],
            'hint' => strtolower($parts['host']),
        ];
    }

    /**
     * @return array{config: array<string, mixed>, secret: array<string, string>, hint: string}
     */
    private function s3(AuditSinkInput $input, UrlGuard $guard): array
    {
        $endpoint = rtrim(trim((string) $input->endpoint), '/');
        $parts = parse_url($endpoint);
        $schemes = $input->allowPrivateNetwork ? ['https', 'http'] : ['https'];

        if ($endpoint === '' || ! is_array($parts) || ! in_array($parts['scheme'] ?? '', $schemes, true) || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ($parts['path'] ?? '') !== '') {
            throw DomainRuleViolation::on('endpoint', 'Enter the endpoint without a bucket or path, e.g. https://s3.eu-central-1.amazonaws.com.');
        }

        $this->vet($guard, $endpoint.'/', 'endpoint', $input->allowPrivateNetwork);

        $bucket = (string) $input->bucket;

        if (preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket) !== 1 || str_contains($bucket, '..')) {
            throw DomainRuleViolation::on('bucket', 'Bucket names are 3–63 lowercase letters, digits, dots and dashes.');
        }

        $region = trim((string) $input->region);
        $region = $region === '' ? 'us-east-1' : $region;

        if (preg_match('/^[a-z0-9-]{1,32}$/D', $region) !== 1) {
            throw DomainRuleViolation::on('region', 'Regions look like eu-central-1.');
        }

        $prefix = trim((string) $input->prefix, '/');

        if ($prefix !== '' && (preg_match('#^[A-Za-z0-9._/-]{1,200}$#D', $prefix) !== 1 || str_contains($prefix, '..') || str_contains($prefix, '//'))) {
            throw DomainRuleViolation::on('prefix', 'Use letters, digits, dots, dashes, underscores and slashes.');
        }

        $accessKey = trim((string) $input->accessKeyId);
        $secretKey = (string) $input->secretAccessKey;

        if ($accessKey === '' || $secretKey === '' || preg_match('/[\x00-\x20\x7f]/', $accessKey.$secretKey) === 1) {
            throw DomainRuleViolation::on('accessKeyId', 'Enter the access key and secret with write access to the bucket.');
        }

        $days = $input->retentionDays ?? 0;
        $mode = strtoupper((string) $input->lockMode);

        if ($days > 0 && ! in_array($mode, ['GOVERNANCE', 'COMPLIANCE'], true)) {
            throw DomainRuleViolation::on('lockMode', 'Choose governance or compliance mode.');
        }

        $config = ['endpoint' => strtolower((string) $parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : ''), 'bucket' => $bucket, 'region' => $region, 'prefix' => $prefix];

        if ($days > 0) {
            $config['retentionDays'] = $days;
            $config['lockMode'] = $mode;
        }

        return [
            'config' => $config,
            'secret' => ['accessKeyId' => $accessKey, 'secretAccessKey' => $secretKey],
            'hint' => strtolower($parts['host']).'/'.$bucket.($prefix === '' ? '' : '/'.$prefix),
        ];
    }

    private function vet(UrlGuard $guard, string $url, string $field, bool $private): void
    {
        try {
            $guard->check($url);
        } catch (UnsafeUrl) {
            throw DomainRuleViolation::on($field, $private
                ? 'This address is not allowed (loopback, link-local and launchroom’s own services never are).'
                : 'This address is not allowed: it must resolve to the public internet. On your own network? Allow the private network below.');
        }
    }
}
