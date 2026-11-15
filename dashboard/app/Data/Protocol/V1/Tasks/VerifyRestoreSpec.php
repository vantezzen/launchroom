<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Data\Protocol\V1\Resources;
use App\Enums\DaemonTaskType;

/**
 * Restores a snapshot into a throwaway container and checks it (protocol
 * VerifyRestoreSpec, docs/14 §10.4).
 */
final class VerifyRestoreSpec extends TaskSpec
{
    /**
     * @param  'database'|'volume'|'base_backup'  $kind
     * @param  list<string>|null  $walTags
     */
    public function __construct(
        public string $kind,
        public string $snapshot,
        public BackupDestination $destination,
        public Resources $resources,
        public ?string $engine = null,
        public ?string $image = null,
        public ?string $dataPath = null,
        public ?string $dataDirectory = null,
        public ?array $walTags = null,
        public ?string $databaseUser = null,
        public ?string $databaseName = null,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::VerifyRestore;
    }
}
