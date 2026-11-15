<?php

declare(strict_types=1);

namespace App\Actions\Instance;

use App\Actions\Servers\ReplaceServer;
use App\Data\Audit\AuditActor;
use App\Exceptions\DomainRuleViolation;
use App\Models\Server;
use App\Models\ServerReplacement;
use App\Models\User;
use App\Queries\ServerReplacementPlan;
use App\Services\Instance\RecoveryMode;

/**
 * Recovery after a control-plane restore (docs/14 §5.2): this server, the
 * newest online control-plane server, takes over from the previous one the
 * restored database still knows, restoring every stateful service that has a
 * backup and re-placing the rest.
 */
final readonly class ReplacePreviousControlPlane
{
    public function __construct(
        private ServerReplacementPlan $plan,
        private ReplaceServer $replace,
    ) {}

    public function handle(User $user, AuditActor $actor): ServerReplacement
    {
        $previous = RecoveryMode::previousControlPlane();
        $current = $previous instanceof Server ? Server::query()
            ->controlPlane()
            ->online()
            ->whereKeyNot($previous->id)
            ->orderByDesc('id')
            ->first() : null;

        if (! $previous instanceof Server || ! $current instanceof Server) {
            throw DomainRuleViolation::on('replace', 'This server hasn’t enrolled yet, or there is no previous control-plane server to replace.');
        }

        $restore = [];

        foreach ($this->plan->for($previous) as $entry) {
            if ($entry['stateful'] && $entry['restore'] !== null) {
                $restore[] = $entry['instance']->public_id;
            }
        }

        return $this->replace->handle($previous, $current, $restore, $user, $actor, recovery: true);
    }
}
