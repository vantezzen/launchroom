<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Audit\CreateAuditCheckpoint;
use App\Models\AuditCheckpoint;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:audit:checkpoint')]
#[Description('Sign the newest position of the audit log when entries were added (docs/09 R34)')]
final class CreateAuditCheckpointCommand extends Command
{
    public function handle(CreateAuditCheckpoint $checkpoint): int
    {
        $created = $checkpoint->handle();

        $this->components->info($created instanceof AuditCheckpoint
            ? "Checkpoint #{$created->id} signs entry #{$created->event_id} ({$created->event_count} entries)."
            : 'No new audit entries since the last checkpoint.');

        return self::SUCCESS;
    }
}
