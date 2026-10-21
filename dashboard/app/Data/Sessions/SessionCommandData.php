<?php

declare(strict_types=1);

namespace App\Data\Sessions;

use Closure;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * A Data tab command from the browser (`POST /sessions/{ses}/commands`),
 * relayed as a signed SessionCommand (docs/03 §11.2). Identifiers follow the
 * same grammar launchroomd checks: no control characters, no `"` or backtick.
 */
final class SessionCommandData extends Data
{
    public const int MAX_STATEMENT_BYTES = 16384;

    /** Table/schema names: 1–63 bytes, no control characters, quotes or backticks. */
    public const string IDENTIFIER = '/^[^\x00-\x1f\x7f"`]{1,63}$/u';

    /**
     * @param  'schema'|'rows'|'keys'|'key'|'query'|'end'  $command
     */
    public function __construct(
        #[In(['schema', 'rows', 'keys', 'key', 'query'])]
        public string $command,
        public string|null|Optional $schema = new Optional,
        public string|null|Optional $table = new Optional,
        #[Min(0), Max(100000)]
        public int|Optional $page = new Optional,
        #[Min(1), Max(100)]
        public int|Optional $pageSize = new Optional,
        #[Max(256)]
        public string|null|Optional $pattern = new Optional,
        public string|null|Optional $cursor = new Optional,
        public string|null|Optional $key = new Optional,
        public string|null|Optional $statement = new Optional,
        public bool|Optional $allowWrites = new Optional,
        public string|Optional $reason = new Optional,
    ) {}

    public static function end(string $reason): self
    {
        $data = new self('schema');
        $data->command = 'end';
        $data->reason = mb_substr($reason, 0, 200);

        return $data;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'table' => ['required_if:command,rows', 'nullable', 'string', 'regex:'.self::IDENTIFIER],
            'schema' => ['nullable', 'string', 'regex:'.self::IDENTIFIER],
            'cursor' => ['nullable', 'string', 'regex:/^[0-9]{1,20}$/D'],
            'pattern' => ['nullable', 'string', 'not_regex:/[\x00-\x1f\x7f]/'],
            'key' => ['required_if:command,key', 'nullable', 'string', 'max:1024', 'not_regex:/\x00/'],
            'statement' => [
                'required_if:command,query', 'nullable', 'string', 'not_regex:/\x00/',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && strlen($value) > self::MAX_STATEMENT_BYTES) {
                        $fail('Statements can be at most 16 KB.');
                    }
                },
            ],
        ];
    }

    /**
     * The command's fields as SessionCommand JSON (only the ones it uses).
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $value = static fn (mixed $v): mixed => $v instanceof Optional ? null : $v;

        return array_filter(match ($this->command) {
            'rows' => [
                'command' => 'rows',
                'table' => array_filter(['schema' => $value($this->schema), 'name' => $value($this->table)], static fn (mixed $v): bool => $v !== null),
                'page' => $value($this->page) ?? 0,
                'pageSize' => $value($this->pageSize) ?? 50,
            ],
            'keys' => ['command' => 'keys', 'pattern' => $value($this->pattern), 'cursor' => $value($this->cursor)],
            'key' => ['command' => 'key', 'key' => $value($this->key)],
            'query' => ['command' => 'query', 'statement' => $value($this->statement), 'allowWrites' => $value($this->allowWrites) === true ? true : null],
            'end' => ['command' => 'end', 'reason' => $value($this->reason)],
            default => ['command' => 'schema'],
        }, static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /**
     * The statement's first keyword (SQL verb, Redis command or the MongoDB
     * command name, the first key of a `{"find": …}` document) for the
     * audit log, never the statement itself.
     */
    public function verb(): string
    {
        $statement = $this->statement instanceof Optional ? '' : (string) $this->statement;
        // Skip leading whitespace, `-- …` line comments and `/* … */` blocks.
        $statement = (string) preg_replace('/\A(?:\s+|--[^\n]*\n?|\/\*.*?\*\/)*/s', '', $statement);

        if (preg_match('/\A\{\s*"([A-Za-z_]{1,32})"\s*:/', $statement, $match) === 1) {
            return strtoupper($match[1]);
        }

        return preg_match('/\A([A-Za-z_]{1,32})/', $statement, $match) === 1 ? strtoupper($match[1]) : 'OTHER';
    }
}
