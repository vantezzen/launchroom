<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Servers\UpdateServerSettingsData;
use App\Enums\AuditAction;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Models\Server;
use App\Queries\MeshServers;
use App\Support\Servers\ServerLabels;
use Illuminate\Support\Facades\DB;

/**
 * Updates a server's placement settings: labels and the "allow previews" /
 * "allow production" flags (docs/02 §2), and whether it measures its
 * private-network links (docs/05 §2.1; re-renders every mesh server, whose
 * peer lists include it only while it measures). The scheduler honours them for new
 * placements; nothing running is moved (the next deploy places replicas by
 * the new rules), so a change never causes downtime by itself.
 */
final readonly class UpdateServerSettings
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @throws DomainRuleViolation
     */
    public function handle(Server $server, UpdateServerSettingsData $data, AuditActor $actor): Server
    {
        $labels = ServerLabels::validate($data->labels, 'labels');

        return DB::transaction(function () use ($server, $data, $labels, $actor): Server {
            $server = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $measureLinks = $data->measureLinks ?? $server->measure_links;
            $before = ['labels' => $server->labels, 'allowPreviews' => $server->allow_previews, 'allowProduction' => $server->allow_production, 'measureLinks' => $server->measure_links];

            $server->forceFill([
                'labels' => $labels,
                'allow_previews' => $data->allowPreviews,
                'allow_production' => $data->allowProduction,
                'measure_links' => $measureLinks,
            ])->save();

            $after = ['labels' => $labels, 'allowPreviews' => $data->allowPreviews, 'allowProduction' => $data->allowProduction, 'measureLinks' => $measureLinks];

            if ($before['measureLinks'] !== $measureLinks) {
                // Its peers stop (or start) listing it too (docs/05 §2.1).
                event(new NodeStateInvalidated(array_values(array_unique([$server->id, ...MeshServers::ids()]))));
            }

            if ($before !== $after) {
                $this->audit->handle(new AuditEntryData(
                    action: AuditAction::ServerSettingsUpdated,
                    actor: $actor,
                    teamId: $server->team_id,
                    subjectType: 'server',
                    subjectId: $server->public_id,
                    meta: [
                        'labels' => array_map(static fn (string $key, string $value): string => "{$key}={$value}", array_keys($labels), array_values($labels)),
                        'allowPreviews' => $data->allowPreviews,
                        'allowProduction' => $data->allowProduction,
                        'measureLinks' => $measureLinks,
                    ],
                ));
            }

            return $server;
        });
    }
}
