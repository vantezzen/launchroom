<?php

declare(strict_types=1);

namespace App\Support\Projects;

use App\Enums\DatabaseEngine;

/**
 * Databases an app probably needs, from the variables of its `.env.example`
 * (flows.md §3 "api uses Postgres and Redis"). Pure and conservative: only
 * names that unmistakably mean a database count. Values are only read for
 * the engine selectors (`DB_CONNECTION`, the `DATABASE_URL` scheme), never
 * kept.
 */
final class DatabaseSuggestions
{
    /**
     * @return array<string, string> key => value (unquoted), comments and blanks skipped
     */
    public static function parseEnvExample(string $contents): array
    {
        $variables = [];

        foreach (preg_split('/\r\n|\n|\r/', mb_substr($contents, 0, 65536)) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]{0,127})\s*=\s*(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $value = trim($match[2]);

            if (preg_match('/^(["\'])(.*?)\1/', $value, $quoted) === 1) {
                $value = $quoted[2];
            } else {
                $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
            }

            $variables[$match[1]] = mb_substr($value, 0, 200);
        }

        return $variables;
    }

    /**
     * @param  array<string, string>  $variables
     * @param  string  $source  where the variables were found, for the reason ("DATABASE_URL in .env.example")
     * @return list<array{engine: DatabaseEngine, reason: string}>
     */
    public static function fromVariables(array $variables, string $source = '.env.example'): array
    {
        $keys = array_keys($variables);
        $suggestions = [];

        $sql = self::sqlEngine($variables, $source);

        if ($sql !== null) {
            $suggestions[] = ['engine' => $sql[0], 'reason' => $sql[1]];
        }

        $redis = self::first($keys, ['REDIS_URL', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD', 'KV_URL', 'VALKEY_URL', 'VALKEY_HOST']);
        $cacheStore = strtolower($variables['CACHE_STORE'] ?? $variables['CACHE_DRIVER'] ?? $variables['QUEUE_CONNECTION'] ?? $variables['SESSION_DRIVER'] ?? '');

        if ($redis !== null) {
            $engine = str_starts_with($redis, 'VALKEY') ? DatabaseEngine::Valkey : DatabaseEngine::Redis;
            $suggestions[] = ['engine' => $engine, 'reason' => "{$redis} in {$source}"];
        } elseif ($cacheStore === 'redis') {
            $suggestions[] = ['engine' => DatabaseEngine::Redis, 'reason' => 'Redis cache or queue in '.$source];
        }

        $analytics = self::first($keys, ['CLICKHOUSE_URL', 'CLICKHOUSE_DATABASE_URL', 'CLICKHOUSE_HOST']);

        if ($analytics !== null) {
            $suggestions[] = ['engine' => DatabaseEngine::ClickHouse, 'reason' => "{$analytics} in {$source}"];
        }

        return $suggestions;
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{0: DatabaseEngine, 1: string}|null
     */
    private static function sqlEngine(array $variables, string $source): ?array
    {
        $connection = strtolower($variables['DB_CONNECTION'] ?? '');

        if (in_array($connection, ['pgsql', 'postgres', 'postgresql'], true)) {
            return [DatabaseEngine::Postgres, 'DB_CONNECTION=pgsql in '.$source];
        }

        if (in_array($connection, ['mysql', 'mariadb'], true)) {
            return [DatabaseEngine::MySql, "DB_CONNECTION={$connection} in {$source}"];
        }

        if ($connection === 'mongodb') {
            return [DatabaseEngine::MongoDb, 'DB_CONNECTION=mongodb in '.$source];
        }

        foreach (['DATABASE_URL', 'DB_URL', 'POSTGRES_URL', 'POSTGRES_PRISMA_URL'] as $key) {
            if (! isset($variables[$key])) {
                continue;
            }

            $scheme = strtolower((string) strtok($variables[$key], ':'));

            return match (true) {
                str_starts_with($scheme, 'mysql') || $scheme === 'mariadb' => [DatabaseEngine::MySql, "{$key} in {$source}"],
                str_starts_with($scheme, 'mongodb') => [DatabaseEngine::MongoDb, "{$key} in {$source}"],
                str_starts_with($scheme, 'sqlite') || $scheme === 'file' => null,
                default => [DatabaseEngine::Postgres, "{$key} in {$source}"],
            };
        }

        $keys = array_keys($variables);

        if (($key = self::first($keys, ['PGHOST', 'PGDATABASE', 'POSTGRES_HOST', 'POSTGRES_DB', 'POSTGRES_PASSWORD'])) !== null) {
            return [DatabaseEngine::Postgres, "{$key} in {$source}"];
        }

        if (($key = self::first($keys, ['MYSQL_HOST', 'MYSQL_DATABASE', 'MYSQL_URL'])) !== null) {
            return [DatabaseEngine::MySql, "{$key} in {$source}"];
        }

        if (($key = self::first($keys, ['MONGO_URL', 'MONGODB_URI', 'MONGODB_URL', 'MONGO_URI'])) !== null) {
            return [DatabaseEngine::MongoDb, "{$key} in {$source}"];
        }

        // Laravel without DB_CONNECTION defaults to SQLite: only DB_HOST alone means a server.
        if ($connection === '' && isset($variables['DB_HOST'], $variables['DB_DATABASE'])) {
            return [DatabaseEngine::Postgres, 'DB_HOST in '.$source];
        }

        return null;
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $candidates
     */
    private static function first(array $keys, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $keys, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
