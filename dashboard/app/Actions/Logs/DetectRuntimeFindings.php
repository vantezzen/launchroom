<?php

declare(strict_types=1);

namespace App\Actions\Logs;

use App\Data\Deployments\RuntimeFindingData;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\LogChunk;
use App\Support\Deployments\Failures\RuntimeFindings;
use Illuminate\Support\Facades\Cache;

/**
 * Reads a server's new runtime log chunks for known problems of live apps
 * (G12, RuntimeFindings) and keeps the first one per live deployment
 * (`deployments.runtime_finding`). Chunks are read once: the last chunk id
 * read per server is kept in the cache, and a missing mark starts from the
 * newest chunks only. Idempotent: a deployment keeps its first finding.
 */
final readonly class DetectRuntimeFindings
{
    private const int BATCH = 200;

    public function handle(int $serverId): void
    {
        $mark = "runtime-findings:read:{$serverId}";
        $after = Cache::get($mark);

        if (! is_int($after)) {
            $newest = LogChunk::query()->where('server_id', $serverId)->max('id');
            $after = is_numeric($newest) ? max(0, (int) $newest - self::BATCH) : 0;
        }

        $chunks = LogChunk::query()
            ->where('server_id', $serverId)
            ->where('id', '>', $after)
            ->where('runtime', true)
            ->whereNotNull('deployment_id')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get(['id', 'deployment_id', 'lines', 'created_at']);

        if ($chunks->isEmpty()) {
            return;
        }

        Cache::put($mark, $chunks->last()->id, now()->addDay());

        $found = [];

        foreach ($chunks as $chunk) {
            $deploymentId = $chunk->deployment_id;

            if (! is_int($deploymentId) || isset($found[$deploymentId])) {
                continue;
            }

            // launchroom's own lines (exits, restarts) are not the app's.
            $texts = array_values(array_map(
                static fn (array $line): string => $line['text'],
                array_filter($chunk->lines, static fn (array $line): bool => $line['stream'] !== 'system'),
            ));
            $finding = RuntimeFindings::scan($texts, ($chunk->created_at ?? now())->toIso8601ZuluString());

            if ($finding instanceof RuntimeFindingData) {
                $found[$deploymentId] = $finding;
            }
        }

        foreach ($found as $deploymentId => $finding) {
            $deployment = Deployment::query()
                ->whereKey($deploymentId)
                ->where('status', DeploymentStatus::Active->value)
                ->whereNull('runtime_finding')
                ->first();

            $deployment?->forceFill(['runtime_finding' => $finding])->save();
        }
    }
}
