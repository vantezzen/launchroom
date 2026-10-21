<?php

declare(strict_types=1);

namespace App\Support\Logs;

/**
 * The log explorer's query language, parsed exactly like the client
 * (features/logs/explorer/query.ts): `service:api -level:debug "connection
 * refused" timeout`. Filters with the same key are OR'd, different keys
 * AND'd, negations exclude; every text term must appear in the line
 * (case-insensitive substring). Unknown keys and empty values stay text.
 */
final readonly class LogSearchQuery
{
    public const array KEYS = ['service', 'level', 'deployment', 'server', 'stream', 'environment', 'project'];

    public const int MAX_TERMS = 16;

    private const array LEVEL_ALIASES = [
        'err' => 'error', 'error' => 'error', 'fatal' => 'error',
        'warn' => 'warning', 'warning' => 'warning',
        'info' => 'info', 'debug' => 'debug',
    ];

    /**
     * @param  array<string, list<array{value: string, negated: bool}>>  $filters
     * @param  list<string>  $text  lowercase terms
     */
    public function __construct(
        public array $filters = [],
        public array $text = [],
    ) {}

    public static function parse(?string $input): self
    {
        $filters = [];
        $text = [];
        preg_match_all('/(-?[a-z]+:"[^"]*"?|-?[a-z]+:\S+|"[^"]*"?|\S+)/i', (string) $input, $matches);

        foreach ($matches[0] as $token) {
            if (preg_match('/^(-?)([a-z]+):(.+)$/i', $token, $m) === 1 && in_array(strtolower($m[2]), self::KEYS, true)) {
                $key = strtolower($m[2]);
                $value = trim(self::unquote($m[3]));

                if ($value !== '') {
                    if ($key === 'level') {
                        $value = self::LEVEL_ALIASES[strtolower($value)] ?? strtolower($value);
                    }

                    $filters[$key][] = ['value' => $value, 'negated' => $m[1] === '-'];

                    continue;
                }
            }

            $term = mb_strtolower(trim(self::unquote($token)));

            if ($term !== '' && count($text) < self::MAX_TERMS) {
                $text[] = $term;
            }
        }

        return new self($filters, $text);
    }

    /**
     * @return list<string>
     */
    public function positive(string $key): array
    {
        return array_values(array_map(static fn (array $f): string => $f['value'], array_filter($this->filters[$key] ?? [], static fn (array $f): bool => ! $f['negated'])));
    }

    /**
     * @return list<string>
     */
    public function negative(string $key): array
    {
        return array_values(array_map(static fn (array $f): string => $f['value'], array_filter($this->filters[$key] ?? [], static fn (array $f): bool => $f['negated'])));
    }

    public function has(string $key): bool
    {
        return ($this->filters[$key] ?? []) !== [];
    }

    private static function unquote(string $value): string
    {
        return (string) preg_replace(['/^"/', '/"$/'], '', $value);
    }
}
