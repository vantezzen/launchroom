<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Enums\Backups\DestinationStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\BackupDestination;
use App\Services\Backups\DestinationSecrets;
use App\Services\Backups\EndpointGuard;
use App\Services\Backups\Process\ProcessFailed;
use App\Services\Backups\Process\ResticCli;
use App\Services\Backups\Process\ResticTarget;
use App\Services\Crypto\Exceptions\DecryptionFailed;

/**
 * "Test destination" (docs/14 §3), run from a queued job on the control
 * plane, never in a request: re-checks the endpoint, opens the restic
 * repository (initialising it on first use, which is the write test) and
 * lists its snapshots (the read test). The outcome lands on the destination
 * (status, last_checked_at, a masked one-line error).
 *
 * Why the control plane and not a daemon: it is the same restic the
 * control-plane backup uses, works before any server is enrolled, and keeps
 * the result synchronous for the job.
 */
final readonly class TestBackupDestination
{
    public function __construct(
        private EndpointGuard $guard,
        private DestinationSecrets $secrets,
        private ResticCli $restic,
    ) {}

    public function handle(BackupDestination $destination): DestinationStatus
    {
        $restic = $this->restic;
        $destination->markChecking();

        try {
            $this->guard->check($destination->endpoint, $destination->allow_private_network);

            if (! $restic->available()) {
                throw ProcessFailed::message('restic is not installed in this dashboard image.');
            }

            $target = ResticTarget::for($destination, $this->secrets->reveal($destination));
            $restic->openOrInit($target);
            $restic->snapshots($target, []);

            $destination->markChecked(DestinationStatus::Ok);
        } catch (ProcessFailed|DomainRuleViolation $e) {
            $destination->markChecked(DestinationStatus::Failing, $e->getMessage());
        } catch (DecryptionFailed) {
            $destination->markChecked(DestinationStatus::Failing, 'The stored credentials could not be decrypted (was the KEK rotated?).');
        }

        return $destination->status;
    }
}
