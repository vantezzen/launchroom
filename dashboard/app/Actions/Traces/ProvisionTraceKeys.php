<?php

declare(strict_types=1);

namespace App\Actions\Traces;

use App\Enums\DaemonFeature;
use App\Events\NodeStateInvalidated;
use App\Models\Server;
use App\Models\Traces\TraceKey;
use App\Services\Traces\TraceKeys;
use Illuminate\Support\Facades\DB;

/**
 * The one writer of request trace keys (docs/23 §4): every approved edge
 * whose launchroomd announced `requestTrace` gets its own AES-256 key; a key
 * older than 30 days is retired for a new one, and keys are deleted 90 days
 * after their retirement, so every trace stays readable for at least 90 days. Runs from
 * the scheduler (`launchroom:traces:keys`) and for the server being rendered
 * before its render (App\Actions\Servers\PrepareNodeStateRender). Renders
 * only read the current key; edges that got a new one re-render.
 */
final readonly class ProvisionTraceKeys
{
    public const int ROTATE_AFTER_DAYS = 30;

    public const int READABLE_DAYS = 90;

    public function __construct(
        private TraceKeys $keys,
    ) {}

    /**
     * All edges, or only $only (before its render, which then picks the key
     * up itself: no re-render is asked for).
     *
     * @return list<int> ids of the servers that got a new key
     */
    public function handle(?Server $only = null): array
    {
        $servers = Server::query()
            ->edges()
            ->whereJsonContains('daemon_features', DaemonFeature::RequestTrace->value)
            ->when($only instanceof Server, static fn ($query) => $query->whereKey($only?->id))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $created = [];

        foreach ($servers as $serverId) {
            if (is_int($serverId) && $this->ensure($serverId)) {
                $created[] = $serverId;
            }
        }

        if (! $only instanceof Server) {
            TraceKey::query()->where('retired_at', '<=', now()->subDays(self::READABLE_DAYS))->delete();
            NodeStateInvalidated::servers($created);
        }

        return $created;
    }

    /**
     * @return bool whether a new key was created
     */
    private function ensure(int $serverId): bool
    {
        return DB::transaction(function () use ($serverId): bool {
            // One provisioner per edge at a time (scheduler and render preparation can overlap).
            $server = Server::query()->whereKey($serverId)->lockForUpdate()->first();

            if (! $server instanceof Server) {
                return false;
            }

            $current = $this->keys->current($server);

            if ($current instanceof TraceKey && $current->created_at->greaterThan(now()->subDays(self::ROTATE_AFTER_DAYS))) {
                return false;
            }

            TraceKey::query()->where('server_id', $server->id)->whereNull('retired_at')->update(['retired_at' => now()]);
            $this->keys->create($server, random_bytes(32));

            return true;
        });
    }
}
