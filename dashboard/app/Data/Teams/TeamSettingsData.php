<?php

declare(strict_types=1);

namespace App\Data\Teams;

use Spatie\LaravelData\Data;

/**
 * `teams.settings` (docs/07).
 */
final class TeamSettingsData extends Data
{
    public function __construct(
        /** New servers wait for an admin's approval after enrolling (docs/03 §3). */
        public bool $requireServerApproval = false,
        /** Every project: production secrets must differ from preview (docs/06 §3.1). */
        public bool $productionSecretsMustDiffer = false,
        /** Previews: largest database (latest backup size, MB) "clone from another database" may copy (docs/14 §6); null = the install default. */
        public ?int $previewCloneLimitMb = null,
        /** Console sessions ship a masked transcript of their output (opt-in, docs/03 §11.5). */
        public bool $recordConsoleSessions = false,
        /** "Generate a Dockerfile" is offered to this team (opt-in, docs/20 §1; the instance must allow it too). */
        public bool $generateDockerfiles = false,
    ) {}
}
