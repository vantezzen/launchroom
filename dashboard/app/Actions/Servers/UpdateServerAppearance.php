<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Servers\ServerLocationInputData;
use App\Data\Servers\UpdateServerAppearanceData;
use App\Enums\AuditAction;
use App\Enums\DeviceKind;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

/**
 * Stores the device a user picked for a server and its location
 * (docs/design/direction.md §7.3: "magic with an escape hatch"). Display only:
 * nothing about placement or the server's state changes.
 */
final readonly class UpdateServerAppearance
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Server $server, UpdateServerAppearanceData $data, AuditActor $actor): Server
    {
        return DB::transaction(function () use ($server, $data, $actor): Server {
            $server = Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $meta = [];

            if (! $data->device instanceof Optional && $server->device_override !== $data->device) {
                $server->device_override = $data->device;
                $meta['device'] = $data->device instanceof DeviceKind ? $data->device->value : null;
            }

            if (! $data->location instanceof Optional) {
                $location = $data->location instanceof ServerLocationInputData ? [
                    'label' => trim($data->location->label),
                    'latitude' => round($data->location->latitude, 4),
                    'longitude' => round($data->location->longitude, 4),
                    'countryCode' => $data->location->countryCode,
                ] : null;

                if ($server->location !== $location) {
                    $server->location = $location;
                    $meta['location'] = $location['label'] ?? null;
                }
            }

            if ($meta === []) {
                return $server;
            }

            $server->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServerAppearanceUpdated,
                actor: $actor,
                teamId: $server->team_id,
                subjectType: 'server',
                subjectId: $server->public_id,
                meta: $meta,
            ));

            return $server;
        });
    }
}
