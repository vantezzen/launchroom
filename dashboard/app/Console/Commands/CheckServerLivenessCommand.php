<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Daemons\ReleaseStuckBuildPins;
use App\Actions\Daemons\RequeueExpiredLeases;
use App\Actions\Servers\MarkStaleServers;
use App\Actions\Servers\RescheduleDeadServers;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:servers:check-liveness')]
#[Description('Mark servers that stopped polling as unreachable/offline, move stateless replicas off dead servers, requeue expired task leases and release stuck build pins')]
final class CheckServerLivenessCommand extends Command
{
    public function handle(MarkStaleServers $markStaleServers, RescheduleDeadServers $rescheduleDeadServers, RequeueExpiredLeases $requeueExpiredLeases, ReleaseStuckBuildPins $releaseStuckBuildPins): int
    {
        $servers = $markStaleServers->handle();
        $moved = $rescheduleDeadServers->handle();
        $tasks = $requeueExpiredLeases->handle();
        $released = $releaseStuckBuildPins->handle();

        $this->components->info("{$servers} server status change(s), {$moved} replica(s) rescheduled, {$tasks} expired lease(s), {$released} build pin(s) released.");

        return self::SUCCESS;
    }
}
