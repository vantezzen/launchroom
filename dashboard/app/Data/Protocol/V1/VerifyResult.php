<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * TaskCompletion.verify (protocol/schema/v1/task-completion.json): the
 * outcome of a verify_restore task (docs/14 §10.4). `passed` is false when a
 * check failed; the task itself still succeeded.
 */
final class VerifyResult extends Data
{
    /**
     * @param  list<array{name: string, passed: bool, detail?: string}>  $checks
     */
    public function __construct(
        public bool $passed,
        #[Max(16)]
        public array $checks,
        #[Min(0)]
        public int $durationMs,
        #[Min(0)]
        public int|Optional $tables = new Optional,
        #[Min(0)]
        public int|Optional $rows = new Optional,
        #[Min(0)]
        public int|Optional $files = new Optional,
        #[Min(0)]
        public int|Optional $bytes = new Optional,
        public string|null|Optional $restoredTo = new Optional,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'checks' => ['present', 'array', 'max:16'],
            'checks.*.name' => ['required', 'string', 'in:restore,replay,start,query,amcheck,mysqlcheck,rdb,archive,validate,checktable'],
            'checks.*.passed' => ['required', 'boolean'],
            'checks.*.detail' => ['sometimes', 'string', 'max:512'],
            'restoredTo' => ['sometimes', 'nullable', 'string', 'regex:'.Report::TIME_PATTERN],
        ];
    }
}
