<?php

declare(strict_types=1);

namespace App\Actions\Domains;

use App\Data\Protocol\V1\Tasks\CertRetrySpec;
use App\Enums\CertStatus;
use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Domain;
use App\Models\Server;
use App\Models\User;
use App\Services\Tasks\DaemonTasks;
use Illuminate\Support\Facades\DB;

/**
 * "Retry now" for a domain without a certificate (G16): a `cert_retry`
 * task on every online edge of the team that understands it. Each edge
 * reloads its routes (at most once a minute) and starts issuing for the
 * host; the outcome arrives with its next report. Refused while the
 * certificate authority's rate limit lasts (`cert_retry_after`) and for an
 * issued certificate. An edge that still has an open retry gets no second.
 */
final readonly class RetryCertificate
{
    public const int TIMEOUT_SECONDS = 300;

    public function __construct(
        private DaemonTasks $tasks,
    ) {}

    /**
     * @return list<DaemonTask>
     */
    public function handle(Domain $domain, ?User $user = null): array
    {
        if ($domain->cert_status === CertStatus::Issued) {
            throw DomainRuleViolation::conflict("{$domain->hostname} already has a certificate.");
        }

        if ($domain->cert_retry_after?->isFuture() === true) {
            throw DomainRuleViolation::conflict('Let’s Encrypt allows the next try at '.$domain->cert_retry_after->format('j M H:i').' UTC. launchroom tries again then.');
        }

        $edges = Server::query()
            ->where('team_id', $domain->team_id)
            ->where('status', ServerStatus::Online->value)
            ->withRole(ServerRole::Edge)
            ->orderBy('id')
            ->get()
            ->filter(static fn (Server $server): bool => $server->supportsDaemonFeature(DaemonFeature::CertificateRetry));

        if ($edges->isEmpty()) {
            throw DomainRuleViolation::conflict('No online server that serves traffic can retry certificates yet. Update launchroom on your servers, or wait for the automatic retry.');
        }

        return DB::transaction(function () use ($domain, $edges, $user): array {
            $tasks = [];

            foreach ($edges as $edge) {
                Server::query()->whereKey($edge->id)->lockForUpdate()->first();
                $open = DaemonTask::query()
                    ->where('server_id', $edge->id)
                    ->where('type', DaemonTaskType::CertRetry->value)
                    ->open()
                    ->latest('id')
                    ->first();

                $tasks[] = $open ?? $this->tasks->create($domain->team_id, new CertRetrySpec([$domain->hostname]), self::TIMEOUT_SECONDS, pin: $edge, requestedById: $user?->id);
            }

            return $tasks;
        });
    }
}
