<?php

declare(strict_types=1);

namespace App\Data\Backups;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * "What's protected", grouped: launchroom itself, then one group per project.
 */
final class BackupGroupData extends Data
{
    /**
     * @param  list<BackupItemData>  $items  failed first, then overdue, running, queued, waiting, paused, ok
     */
    public function __construct(
        /** `launchroom` or the project's public id. */
        public string $key,
        #[LiteralTypeScriptType("'launchroom' | 'project'")]
        public string $kind,
        public ?string $projectId,
        public string $name,
        public array $items,
    ) {}
}
