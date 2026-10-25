<?php

declare(strict_types=1);

namespace App\Actions\Updates;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\Updates\FleetUpdateStatus;
use App\Exceptions\DomainRuleViolation;
use App\Jobs\Updates\AdvanceUpdatesJob;
use App\Models\Updates\ControlPlaneUpdate;
use App\Models\Updates\FleetUpdate;
use App\Models\User;
use App\Queries\FleetUpdateCandidates;
use App\Support\ImageReference;
use App\Support\Semver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * "Update all servers" (docs/11 §4): rolls launchroomd on every enrolled
 * server to this dashboard's version, one server at a time, servers with the
 * edge role last. The control-plane server is left out (its launchroomd is
 * updated with the control plane), as are servers already at the version and
 * servers that run a newer one (no downgrades).
 */
final readonly class StartFleetUpdate
{
    public function __construct(
        private RecordAuditEvent $audit,
        private FleetUpdateCandidates $candidates,
    ) {}

    /**
     * @param  User|null  $user  null when the new dashboard starts it after a control-plane update whose owner was removed
     */
    public function handle(?User $user, AuditActor $actor): FleetUpdate
    {
        $target = config()->string('launchroom.version');
        $image = config('launchroom.daemon.image');
        $version = Semver::parse($target);

        if (! $version instanceof Semver || str_ends_with($target, '-dev')) {
            throw DomainRuleViolation::on('fleet', "This dashboard ({$target}) is not a release, so there is no server version to roll out.");
        }

        if (! is_string($image) || ! ImageReference::isDigestPinned($image)) {
            throw DomainRuleViolation::on('fleet', 'The launchroomd image of this release is not pinned by digest (LAUNCHROOM_DAEMON_IMAGE).');
        }

        $update = DB::transaction(function () use ($user, $target, $image): FleetUpdate {
            if (FleetUpdate::query()->where('status', FleetUpdateStatus::Running->value)->lockForUpdate()->exists()
                || ControlPlaneUpdate::active() instanceof ControlPlaneUpdate) {
                throw DomainRuleViolation::conflict('An update is already running.');
            }

            $servers = $this->candidates->forVersion($target);

            if ($servers === []) {
                throw DomainRuleViolation::on('fleet', 'Every server already runs this version.');
            }

            try {
                $update = FleetUpdate::query()->create(['to_version' => $target, 'daemon_image' => $image, 'actor_id' => $user?->id]);
            } catch (UniqueConstraintViolationException) {
                throw DomainRuleViolation::conflict('An update is already running.');
            }

            foreach ($servers as $position => $server) {
                $update->steps()->create([
                    'server_id' => $server->id,
                    'position' => $position + 1,
                    'server_name' => mb_substr($server->name, 0, 100),
                    'from_version' => $server->daemon_version,
                ]);
            }

            return $update;
        });

        $this->audit->handle(new AuditEntryData(
            action: AuditAction::FleetUpdateStarted,
            actor: $actor,
            subjectType: 'fleet_update',
            subjectId: $update->public_id,
            meta: ['to' => $target, 'servers' => $update->steps()->count()],
        ));

        dispatch(new AdvanceUpdatesJob);

        return $update;
    }
}
