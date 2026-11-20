<?php

declare(strict_types=1);

namespace App\Console\Commands\Moves;

use App\Actions\Moves\TakeOverControlPlane;
use App\Data\Audit\AuditActor;
use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Moves\ControlPlaneMove;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * Makes this dashboard (restored from a hand-over) the control plane
 * (docs/14 §13.4). `install.sh --move` and `./launchroom move continue` run
 * it once the stack is up. Safe to run again: a database that already took
 * over answers `"already": true`.
 *
 * --status tells the installer where the move stands in this database
 * without changing anything: `none` (nothing restored here yet), `ready`
 * (restored, not taken over) or `done` (taken over).
 *
 * A refusal is printed with its reason (on stderr, and as `error` with
 * --json), so the installer can show why.
 */
#[Signature('launchroom:move:takeover {--status : Only report the state of the move in this database} {--json : Machine-readable output}')]
#[Description('Take over as the control plane after a move (used by install.sh --move)')]
final class TakeOverCommand extends Command
{
    public function handle(TakeOverControlPlane $takeOver): int
    {
        $state = $this->state();

        if ($this->option('status')) {
            $this->result(['state' => $state['state'], 'move' => $state['move']], "Move here: {$state['state']}.");

            return self::SUCCESS;
        }

        if ($state['state'] === 'done') {
            $this->result(['already' => true, 'move' => $state['move']], 'This dashboard already took over.');

            return self::SUCCESS;
        }

        try {
            $result = $takeOver->handle(AuditActor::system());
        } catch (DomainRuleViolation $e) {
            $this->output->getErrorStyle()->writeln('The takeover refused: '.$e->getMessage());

            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            return self::FAILURE;
        }

        $this->result($result, "This dashboard took over ({$result['server']} is the control-plane server; {$result['previous']} is retired).");

        return self::SUCCESS;
    }

    /**
     * @return array{state: 'none'|'ready'|'done', move: string|null}
     */
    private function state(): array
    {
        try {
            $move = ControlPlaneMove::query()->latest('id')->first();
        } catch (QueryException) {
            // Before the hand-over was restored here, this database has no tables.
            return ['state' => 'none', 'move' => null];
        }

        return match (true) {
            ! $move instanceof ControlPlaneMove => ['state' => 'none', 'move' => null],
            in_array($move->status, ControlPlaneMoveStatus::handingOver(), true) => ['state' => 'ready', 'move' => $move->public_id],
            $move->status === ControlPlaneMoveStatus::HandedOver && $move->handed_over_at !== null => ['state' => 'done', 'move' => $move->public_id],
            default => ['state' => 'none', 'move' => $move->public_id],
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function result(array $result, string $message): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info($message);
        }
    }
}
