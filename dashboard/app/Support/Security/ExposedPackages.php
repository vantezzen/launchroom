<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * The exposed list (docs/22 §5.3): OS packages and binaries that process
 * requests or run the app's code directly. A vulnerability in anything else
 * of the operating system is "Worth knowing" (reason `os_package`, or
 * `tls_at_edge` for OpenSSL). Pure.
 */
final class ExposedPackages
{
    private const array PATTERNS = [
        // JavaScript runtimes.
        '/^(?:nodejs|node|bun|deno)$/D',
        // Python.
        '/^(?:python3\.\d+|libpython3\.\d+|python3|python)$/D',
        // PHP.
        '/^(?:php|php8\.\d+|php8\.\d+-(?:cli|fpm|common)|php-fpm|php-cli|frankenphp)$/D',
        // Ruby.
        '/^(?:ruby|ruby3\.\d+|libruby3\.\d+)$/D',
        // Java and .NET.
        '/^(?:openjdk-[0-9a-z.]+-jre.*|openjdk-[0-9a-z.]+-jdk.*|java|aspnetcore-runtime-.+|dotnet-runtime-.+|dotnet)$/D',
        // Web servers inside the app image.
        '/^(?:nginx|apache2|httpd|caddy)$/D',
    ];

    private const string TLS = '/^(?:openssl|libssl\d*(?:\.\d+)*(?:t64)?|libcrypto\d*(?:\.\d+)*|openssl-libs)$/D';

    public static function isExposed(string $ecosystem, string $name): bool
    {
        // Go's standard library compiled into a Go binary.
        if ($ecosystem === 'go') {
            return $name === 'stdlib';
        }

        return array_any(self::PATTERNS, fn (string $pattern): bool => preg_match($pattern, $name) === 1);
    }

    public static function isTls(string $name): bool
    {
        return preg_match(self::TLS, $name) === 1;
    }
}
