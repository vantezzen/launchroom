<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditSink;
use App\Services\Audit\AuditForwarder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:audit:deliver')]
#[Description('Forward pending audit entries to every sink that is due (retries and backlog, docs/09 R34)')]
final class DeliverAuditLogCommand extends Command
{
    public function handle(AuditForwarder $forwarder): int
    {
        foreach (AuditSink::query()->where('enabled', true)->orderBy('id')->get(['id']) as $sink) {
            $forwarder->deliver($sink->id);
        }

        return self::SUCCESS;
    }
}
