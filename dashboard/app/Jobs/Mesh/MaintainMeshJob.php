<?php

declare(strict_types=1);

namespace App\Jobs\Mesh;

use App\Actions\Mesh\MaintainMesh;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs MaintainMesh off the request path (after a server joined). Unique:
 * bursts collapse. MaintainMesh never throws; the scheduler runs it too.
 */
#[Timeout(120)]
#[Tries(1)]
#[UniqueFor(60)]
final class MaintainMeshJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(MaintainMesh $maintainMesh): void
    {
        $maintainMesh->handle();
    }
}
