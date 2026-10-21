<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * TaskCompletion.dataImport (protocol/schema/v1/task-completion.json): the
 * outcome of a db_import or volume_import task (D55, docs/14 §12.2).
 */
final class DataImportResult extends Data
{
    public const array FORMATS = ['postgres_custom', 'postgres_sql', 'mysql_sql', 'mongodb_archive', 'clickhouse_backup', 'redis_rdb', 'tar', 'zip'];

    /**
     * @param  'inspect'|'import'  $mode
     * @param  list<string>|Optional  $warnings
     * @param  array{uid: int, gid: int}|null|Optional  $rootOwner
     */
    public function __construct(
        #[In(['inspect', 'import'])]
        public string $mode,
        public bool $empty,
        #[Min(0)]
        public int $durationMs,
        #[Min(0)]
        public int|Optional $tables = new Optional,
        #[Min(0)]
        public int|Optional $files = new Optional,
        #[Min(0)]
        public int|Optional $sizeBytes = new Optional,
        #[Max(64)]
        public string|Optional $serverVersion = new Optional,
        public array|null|Optional $rootOwner = new Optional,
        #[In(self::FORMATS)]
        public string|Optional $format = new Optional,
        public bool|Optional $compressed = new Optional,
        #[Max(64)]
        public string|Optional $sourceVersion = new Optional,
        #[Min(0)]
        public int|Optional $readBytes = new Optional,
        public array|null|Optional $warnings = new Optional,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'rootOwner.uid' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'rootOwner.gid' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'warnings' => ['sometimes', 'nullable', 'array', 'max:8'],
            'warnings.*' => ['string', 'max:256'],
        ];
    }
}
