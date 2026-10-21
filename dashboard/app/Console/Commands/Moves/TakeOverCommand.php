<?php

declare(strict_types=1);

namespace App\Console\Commands\Moves;

use App\Actions\Moves\TakeOverControlPlane;
use App\Data\Audit\AuditActor;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Makes this dashboard (restored from a hand-over) the control plane
 * (docs/14 §13.4). `install.sh --move` runs it once the stack is up.
 */
#[Signature('launchroom:move:takeover {--json : Machine-readable output}')]
#[Description('Take over as the control plane after a move (used by install.sh --move)')]
final class TakeOverCommand extends Command
{
    public function handle(TakeOverControlPlane $takeOver): int
    {
        try {
            $result = $takeOver->handle(AuditActor::system());
        } catch (DomainRuleViolation $e) {
            $this->fail($e->getMessage());
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("This dashboard took over ({$result['server']} is the control-plane server; {$result['previous']} is retired).");
        }

        return self::SUCCESS;
    }
}
