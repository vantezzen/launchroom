<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Exceptions\DomainRuleViolation;
use SensitiveParameter;

/**
 * A database connection URL to import from (docs/14 §12.3). Checked by
 * grammar here and again by launchroomd; it is sealed to the target server
 * at once and never stored readable. Only {@see self::label()} (no user, no
 * password) is kept for display.
 */
final readonly class SourceUrl
{
    private const array SCHEMES = [
        'postgres' => ['postgres', 'postgresql'],
        'mysql' => ['mysql', 'mariadb'],
        'mongodb' => ['mongodb', 'mongodb+srv'],
    ];

    private function __construct(
        #[SensitiveParameter]
        public string $url,
        public string $label,
        public string $host,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public static function parse(#[SensitiveParameter] string $url, string $engine): self
    {
        $url = trim($url);
        $schemes = self::SCHEMES[$engine] ?? ['postgres'];
        $example = match ($engine) {
            'postgres' => 'postgres://user:password@db.example.com:5432/app',
            'mysql' => 'mysql://user:password@db.example.com:3306/app',
            default => 'mongodb://user:password@db.example.com:27017/app',
        };

        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw DomainRuleViolation::on('sourceUrl', "Paste a connection URL like {$example}.");
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';

        if (! is_array($parts) || ! in_array($scheme, $schemes, true)) {
            throw DomainRuleViolation::on('sourceUrl', "This database takes a URL that starts with {$schemes[0]}://, like {$example}.");
        }

        $host = strtolower($parts['host'] ?? '');

        if ($host === '' || preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?)$/', $host) !== 1) {
            throw DomainRuleViolation::on('sourceUrl', 'The URL needs a host name or address.');
        }

        $database = trim($parts['path'] ?? '', '/');

        if ($database === '' || preg_match('/^[A-Za-z0-9_.$-]{1,63}$/', $database) !== 1) {
            throw DomainRuleViolation::on('sourceUrl', "Add the database name at the end of the URL, like {$example}.");
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return new self($url, "{$scheme}://{$host}{$port}/{$database}", $host);
    }
}
