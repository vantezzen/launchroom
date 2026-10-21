<?php

declare(strict_types=1);

namespace App\Console\Commands\Mesh;

use App\Actions\Mesh\MaintainMesh;
use App\Models\InstanceSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:mesh:maintain')]
#[Description('Keep the built-in mesh converged: API key rotation, ACL policy, node tags, pending server keys (docs/05 §2)')]
final class MaintainMeshCommand extends Command
{
    public function handle(MaintainMesh $maintainMesh): int
    {
        $maintainMesh->handle();
        $error = InstanceSettings::current()->mesh_error;

        if ($error !== null) {
            $this->components->warn($error);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
