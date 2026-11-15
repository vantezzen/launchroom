<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Data\Audit\AuditActor;
use App\Models\Backups\DeletionRequest;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Backups\FinalBackups;
use Illuminate\Database\Eloquent\Collection;

/**
 * Before data goes away (docs/14 §9.13): returns the subject's open
 * deletion request (refreshed), or opens one and starts the final backups
 * when some of $instances need one; null when nothing needs one. With
 * $skip the request goes ahead without them (callers authorize). Callers
 * own their transaction and wait while the request doesn't allow deletion.
 */
final readonly class RequestFinalBackups
{
    public function __construct(
        private FinalBackups $finalBackups,
        private StartFinalBackups $start,
        private OverrideFinalBackup $override,
    ) {}

    /**
     * @param  Collection<int, ServiceInstance>  $instances
     */
    public function handle(string $type, int $subjectId, string $publicId, string $name, int $teamId, Collection $instances, ?User $user, AuditActor $actor, bool $skip = false): ?DeletionRequest
    {
        $open = DeletionRequest::openFor($type, $subjectId, lock: true);

        if ($open instanceof DeletionRequest) {
            $this->finalBackups->refresh($open);

            if ($skip) {
                $this->override->handle($open, $user, $actor);
            }

            return $open;
        }

        $needed = $this->finalBackups->needed($instances);

        if ($needed === []) {
            return null;
        }

        $request = DeletionRequest::query()->create([
            'team_id' => $teamId,
            'subject_type' => $type,
            'subject_id' => $subjectId,
            'subject_public_id' => $publicId,
            'subject_name' => mb_substr($name, 0, 255),
            'requested_by' => $user?->id,
        ]);

        if ($skip) {
            $this->override->handle($request, $user, $actor);

            return $request;
        }

        $this->start->handle($request, $needed, $user, $actor);

        return $request;
    }
}
