<?php

declare(strict_types=1);

namespace App\Actions\Backups;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Connections\CreateConnection;
use App\Actions\Services\RemoveService;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Exceptions\DomainRuleViolation;
use App\Models\Backups\RestoreRun;
use App\Models\Connection;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\Backups\RestoreSwaps;
use Illuminate\Support\Facades\DB;

/**
 * Swap after a restore as a new service (docs/14 §5.1, §9.16): the restored
 * copy takes over from the original **in the restore's environment**, as
 * staged changes that the next Deploy applies (Discard reverts them):
 *
 * - every service connected to the original (canvas edges into it, e.g. the
 *   apps using a database) gets the same connection to the copy;
 * - the original's own connections (e.g. an app with a volume → its
 *   database) are given to the copy as well;
 * - the original's domains (generated and custom) move to the copy;
 * - the original is retired here: its removal is staged ({@see RemoveService},
 *   with a final backup when it has backups).
 *
 * Connections are project-wide but only apply where both ends run, so the
 * original's connections keep working in other environments (the copy exists
 * in this one only), and here only the copy's apply once the original is
 * removed. The user confirms by typing the original's name.
 */
final readonly class SwapRestoredService
{
    public function __construct(
        private CreateConnection $connect,
        private RemoveService $remove,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(RestoreRun $run, string $confirmation, User $user, AuditActor $actor): void
    {
        $original = DB::transaction(function () use ($run, $confirmation, $user, $actor): ServiceInstance {
            $run = RestoreRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            [$original, $copy] = RestoreSwaps::pair($run);

            Environment::query()->whereKey($original->environment_id)->lockForUpdate()->firstOrFail();

            if (! hash_equals($original->service->slug, $confirmation)) {
                throw DomainRuleViolation::on('confirmation', "Type {$original->service->slug} to confirm. The swap retires {$original->service->name} in this environment.");
            }

            $running = ServiceInstance::query()->current()->where('environment_id', $original->environment_id)->pluck('service_id')
                ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();
            $connected = [];

            // Services using the original now use the copy.
            foreach (Connection::query()->where('to_service_id', $original->service_id)->with('from')->orderBy('id')->get() as $connection) {
                if ($connection->from_service_id === $copy->service_id || ! in_array($connection->from_service_id, $running, true)) {
                    continue;
                }

                if (! Connection::query()->where('from_service_id', $connection->from_service_id)->where('to_service_id', $copy->service_id)->exists()) {
                    $this->connect->handle($connection->from, $copy->service, $connection->mode, $connection->style, $user, $actor);
                    $connected[] = $connection->from->public_id;
                }
            }

            // The copy uses what the original used.
            foreach (Connection::query()->where('from_service_id', $original->service_id)->with('to')->orderBy('id')->get() as $connection) {
                if ($connection->to_service_id === $copy->service_id || ! in_array($connection->to_service_id, $running, true)) {
                    continue;
                }

                if (! Connection::query()->where('from_service_id', $copy->service_id)->where('to_service_id', $connection->to_service_id)->exists()) {
                    $this->connect->handle($copy->service, $connection->to, $connection->mode, $connection->style, $user, $actor);
                    $connected[] = $connection->to->public_id;
                }
            }

            /** @var list<string> $domains */
            $domains = Domain::query()->where('service_instance_id', $original->id)->orderBy('hostname')->pluck('hostname')->values()->all();
            Domain::query()->where('service_instance_id', $original->id)->update(['service_instance_id' => $copy->id]);

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::RestoreSwapped,
                actor: $actor,
                teamId: $run->team_id,
                subjectType: 'service',
                subjectId: $original->service->public_id,
                meta: [
                    'restore' => $run->public_id,
                    'copy' => $copy->service->public_id,
                    'environment' => $original->environment->public_id,
                    'connected' => $connected,
                    'domains' => $domains,
                ],
            ));

            return $original;
        });

        // Retire the original here (staged; final backup first when configured).
        $this->remove->handle($original, $actor);
    }
}
