<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Spatie\LaravelData\Data;

/**
 * One check of a verification: restore, replay, start, query, amcheck,
 * mysqlcheck (CHECK TABLE), rdb, archive, validate (MongoDB) or checktable (ClickHouse).
 */
final class BackupVerificationCheckData extends Data
{
    public function __construct(
        public string $name,
        public bool $passed,
        public ?string $detail,
    ) {}
}
