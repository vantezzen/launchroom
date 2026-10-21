<?php

declare(strict_types=1);

namespace App\Console\Commands\Mesh;

use App\Actions\Mesh\EnableMesh;
use App\Data\Audit\AuditActor;
use App\Exceptions\DomainRuleViolation;
use App\Services\Mesh\Exceptions\HeadscaleUnavailable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:mesh:enable')]
#[Description('Switch the built-in mesh on now instead of when a second server joins (docs/05 §2)')]
final class EnableMeshCommand extends Command
{
    public function handle(EnableMesh $enableMesh): int
    {
        try {
            $enableMesh->handle(AuditActor::system());
        } catch (HeadscaleUnavailable|DomainRuleViolation $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('The mesh is on.');

        return self::SUCCESS;
    }
}
