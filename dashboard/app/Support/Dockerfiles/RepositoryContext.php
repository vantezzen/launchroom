<?php

declare(strict_types=1);

namespace App\Support\Dockerfiles;

/**
 * What the model may see of a repository (docs/20 §2): the second, fail
 * closed filter after launchroomd's. Paths on the deny-list are dropped
 * from the listing and the files, limits are enforced again, and every
 * file's content is scrubbed of credentials before it is stored or sent.
 *
 * Pure: the caller passes the environment's secret values to redact.
 */
final class RepositoryContext
{
    public const int MAX_TREE = 2000;

    public const int MAX_FILES = 48;

    public const int MAX_FILE_BYTES = 32768;

    public const int MAX_TOTAL_BYTES = 262144;

    public const string REDACTED = '[redacted]';

    /** Path components (any depth) that are never listed nor sent. */
    private const array DENIED_NAMES = [
        '.npmrc', '.pypirc', '.netrc', '.git-credentials', 'auth.json', 'kubeconfig', '.dockerconfigjson', '.htpasswd',
    ];

    /** Patterns on one path component, case-insensitive. */
    private const array DENIED_PATTERNS = [
        '/^\.env(?:\..*)?$/i',
        '/^\.yarnrc/i',
        '/\.(?:pem|key|p12|pfx|crt|cer|der|jks|keystore|kdbx|tfvars|tfstate)$/i',
        '/^id_(?:rsa|dsa|ecdsa|ed25519)/i',
        '/^credentials/i',
        '/^secrets?(?:[._-]|$)/i',
        '/^(?:docker-)?compose(?:[._-].*)?\.ya?ml$/i',
        '/^\.git$/i',
    ];

    /** Credential shapes inside text (unanchored). */
    private const array SECRET_PATTERNS = [
        '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?(?:-----END [A-Z0-9 ]*PRIVATE KEY-----|\z)/s',
        '/\b(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]{10,}/',
        '/\bsk-ant-[A-Za-z0-9_-]{16,}/',
        '/\bsk-[A-Za-z0-9_-]{32,}/',
        '/\bgh[pousr]_[A-Za-z0-9]{30,}/',
        '/\bgithub_pat_[A-Za-z0-9_]{30,}/',
        '/\bglpat-[A-Za-z0-9_-]{20,}/',
        '/\bxox[abposr]-[A-Za-z0-9-]{10,}/',
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        '/\bAIza[0-9A-Za-z_-]{35}\b/',
        '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/',
    ];

    /** Names whose assigned values are redacted (`NAME=value`, `"name": "value"`, `name: value`). */
    private const string SECRET_NAME = '[A-Za-z0-9_]*(?:SECRET|TOKEN|PASSWORD|PASSWD|PRIVATE_?KEY|API_?KEY|ACCESS_?KEY|CREDENTIAL)[A-Za-z0-9_]*|[A-Za-z0-9_]*_(?:KEY|PASS|PWD)';

    public static function isDeniedPath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0") || strlen($path) > 1024) {
            return true;
        }

        foreach (explode('/', $path) as $part) {
            if (in_array($part, ['', '.', '..'], true)) {
                return true;
            }

            if (in_array(strtolower($part), self::DENIED_NAMES, true)) {
                return true;
            }

            foreach (self::DENIED_PATTERNS as $pattern) {
                if (preg_match($pattern, $part) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Applies the deny-list, limits and the scrubber to an inspection as
     * launchroomd reported it (TaskCompletion.inspection).
     *
     * @param  array<mixed>  $inspection
     * @param  list<string>  $secretValues  the environment's secret values (redacted when ≥ 6 bytes)
     * @return array{tree: list<string>, treeTruncated: bool, files: list<array{path: string, content: string, truncated: bool}>}
     */
    public static function sanitize(array $inspection, array $secretValues = []): array
    {
        $tree = [];

        foreach (is_array($inspection['tree'] ?? null) ? $inspection['tree'] : [] as $path) {
            if (is_string($path) && ! self::isDeniedPath($path) && count($tree) < self::MAX_TREE) {
                $tree[] = $path;
            }
        }

        $files = [];
        $total = 0;

        foreach (is_array($inspection['files'] ?? null) ? $inspection['files'] : [] as $file) {
            if (! is_array($file) || ! is_string($file['path'] ?? null) || ! is_string($file['content'] ?? null)) {
                continue;
            }

            $path = $file['path'];
            $content = $file['content'];

            if (self::isDeniedPath($path) || count($files) >= self::MAX_FILES || ! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
                continue;
            }

            $truncated = ($file['truncated'] ?? false) === true;

            if (strlen($content) > self::MAX_FILE_BYTES) {
                $content = mb_strcut($content, 0, self::MAX_FILE_BYTES);
                $truncated = true;
            }

            $content = self::scrub($content, $secretValues);

            if ($total + strlen($content) > self::MAX_TOTAL_BYTES) {
                continue;
            }

            $total += strlen($content);
            $files[] = ['path' => $path, 'content' => $content, 'truncated' => $truncated];
        }

        return [
            'tree' => $tree,
            'treeTruncated' => ($inspection['treeTruncated'] ?? false) === true || count($tree) >= self::MAX_TREE,
            'files' => $files,
        ];
    }

    /**
     * Replaces credentials in free text (file contents, log tails).
     *
     * @param  list<string>  $secretValues
     */
    public static function scrub(string $text, array $secretValues = []): string
    {
        // Exact secret values first (longest first, so a value containing another goes whole).
        $values = array_values(array_unique(array_filter($secretValues, static fn (string $value): bool => strlen($value) >= 6)));
        usort($values, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($values as $value) {
            $text = str_replace($value, self::REDACTED, $text);

            foreach (array_unique([base64_encode($value), rtrim(strtr(base64_encode($value), '+/', '-_'), '='), rawurlencode($value)]) as $encoded) {
                if ($encoded !== $value && strlen($encoded) >= 8) {
                    $text = str_replace($encoded, self::REDACTED, $text);
                }
            }
        }

        foreach (self::SECRET_PATTERNS as $pattern) {
            $text = (string) preg_replace($pattern, self::REDACTED, $text);
        }

        // Passwords in URLs: scheme://user:password@host.
        $text = (string) preg_replace('#([a-z][a-z0-9+.-]*://[^/\s:@"\']+:)[^/\s@"\']+@#i', '$1'.self::REDACTED.'@', $text);

        // NAME=value, NAME: value, "name": "value" with a secret-looking name.
        $text = (string) preg_replace(
            '/((?<![A-Za-z0-9_.-])(?:'.self::SECRET_NAME.')(?![A-Za-z0-9_.-])["\']?\s*[:=]\s*)(["\']?)([^\s"\'#,;}]{4,})(\2)/i',
            '$1$2'.self::REDACTED.'$4',
            $text,
        );

        return $text;
    }

    /**
     * Whether a name looks like it holds a credential (ARG/ENV checks).
     */
    public static function looksSecretName(string $name): bool
    {
        return preg_match('/^(?:'.self::SECRET_NAME.')$/i', $name) === 1;
    }

    /**
     * Whether a value has the shape of a credential.
     */
    public static function looksSecretValue(string $value): bool
    {
        foreach (self::SECRET_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return preg_match('#[a-z][a-z0-9+.-]*://[^/\s:@]+:[^/\s@]+@#i', $value) === 1;
    }
}
