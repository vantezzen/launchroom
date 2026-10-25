<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Jobs\RenderServerNodeState;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Support\Facades\DB;

/**
 * Re-renders every server's NodeState when the configuration that goes into
 * all of them changed: the system image pins (a new LAUNCHROOM_CADDY_IMAGE
 * after an update brings the web firewall, docs/22 §6.1), Caddy modules,
 * HSTS, the dashboard upstream, shared certificate storage and the dashboard
 * version (new code may render new fields, such as `routes[].waf`). Nothing
 * else invalidates NodeStates for these, so an updated dashboard would leave
 * edges on the previous Caddy until an unrelated change. Runs every minute
 * from the scheduler; idempotent (unchanged content keeps a NodeState's
 * version, and the stored fingerprint stops repeats).
 */
final readonly class RefreshNodeStates
{
    /**
     * @return int servers queued for a render (0 when nothing changed)
     */
    public function handle(): int
    {
        return DB::transaction(function (): int {
            $settings = InstanceSettings::lockedForUpdate();
            $fingerprint = $this->fingerprint();

            if ($settings->node_state_config_hash === $fingerprint) {
                return 0;
            }

            $queued = 0;

            foreach (Server::query()->orderBy('id')->get() as $server) {
                if ($server->status->isApproved()) {
                    dispatch(new RenderServerNodeState($server->id))->afterCommit();
                    $queued++;
                }
            }

            $settings->forceFill(['node_state_config_hash' => $fingerprint])->save();

            return $queued;
        });
    }

    /**
     * sha256 over the configuration every NodeState depends on. Secrets
     * (the certificate storage password) are left out.
     */
    private function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'version' => config()->string('launchroom.version'),
            'systemImages' => config()->array('launchroom.daemon.system_images'),
            'caddyModules' => config()->boolean('launchroom.ingress.caddy_modules'),
            'hstsMaxAge' => config('launchroom.ingress.hsts_max_age'),
            'dashboardUpstream' => config('launchroom.control_plane.dashboard_upstream'),
            'certStorage' => [config('launchroom.ingress.cert_storage.address'), config('launchroom.ingress.cert_storage.username'), config('launchroom.ingress.cert_storage.key_prefix')],
        ], JSON_THROW_ON_ERROR));
    }
}
