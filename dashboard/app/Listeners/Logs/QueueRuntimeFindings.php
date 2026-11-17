<?php

declare(strict_types=1);

namespace App\Listeners\Logs;

use App\Enums\LogSourceKind;
use App\Events\LogChunksStored;
use App\Jobs\Logs\DetectRuntimeFindingsJob;

/**
 * Runtime output arrived (workload chunks and live tails): read it for
 * known problems of live apps (G12), deduplicated per server.
 */
final class QueueRuntimeFindings
{
    public function handle(LogChunksStored $event): void
    {
        foreach ($event->sources as $source) {
            if ($source['kind'] !== LogSourceKind::Deployment) {
                dispatch(new DetectRuntimeFindingsJob($event->serverId));

                return;
            }
        }
    }
}
