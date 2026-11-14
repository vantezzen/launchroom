<?php

declare(strict_types=1);

namespace App\Data\CommandPalette;

use App\Data\Canvas\NodeStatusData;
use App\Enums\ServiceKind;
use Spatie\LaravelData\Data;

/**
 * Something ⌘K can jump to (docs/08 §8): a project, environment, service,
 * deployment or server, with the ids the client needs to build its URLs.
 * Names, statuses and addresses only: never variable values.
 */
final class PaletteItemData extends Data
{
    public function __construct(
        public string $kind,
        public string $id,
        public string $label,
        public ?string $detail = null,
        public ?string $projectId = null,
        public ?string $environmentId = null,
        /** Deployments and servers: their status enum value. */
        public ?string $status = null,
        /** Projects: the project icon (docs/08 §13). */
        public ?string $iconUrl = null,
        /** Services: the node's status; projects: the environment's aggregate. */
        public ?NodeStatusData $nodeStatus = null,
        /** Services: the live hostname ("Open ↗"). */
        public ?string $domain = null,
        /** Services: glyph key (framework or engine logo). */
        public ?string $logo = null,
        public ?ServiceKind $serviceKind = null,
    ) {}
}
