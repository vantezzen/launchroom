<?php

declare(strict_types=1);

namespace App\Console\Commands\Security;

use App\Actions\Security\PruneSecurityData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:security:prune')]
#[Description('Delete inventories no retained deployment uses, old resolved findings and investigations, and unmatched advisories (docs/22 §3.5)')]
final class PruneSecurityCommand extends Command
{
    public function handle(PruneSecurityData $prune): int
    {
        $result = $prune->handle();
        $this->info("Deleted {$result['inventories']} inventory(ies), {$result['findings']} finding(s), {$result['investigations']} investigation(s), {$result['advisories']} advisory record(s).");

        return self::SUCCESS;
    }
}
