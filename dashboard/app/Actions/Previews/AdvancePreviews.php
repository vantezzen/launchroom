<?php

declare(strict_types=1);

namespace App\Actions\Previews;

use App\Enums\Previews\PreviewStatus;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewSettings;
use App\Models\Project;
use App\Services\Previews\PreviewGate;
use Throwable;

/**
 * Moves previews forward (scheduler tick every minute, plus jobs after
 * deployment status changes and finished tasks): removals that waited for a
 * backup/restore are retried, preparing previews continue ({@see DeployPreview}: databases, clones, then apps), and queued
 * previews start when their project has a free slot (oldest first).
 * Returns the number of previews it touched.
 */
final readonly class AdvancePreviews
{
    public function __construct(
        private DeployPreview $deploy,
        private StartPreview $start,
        private PreviewGate $gate,
        private DestroyPreview $destroy,
    ) {}

    public function handle(?int $teamId = null): int
    {
        $touched = 0;

        // Removals that waited for a running backup or restore.
        $pending = Preview::query()
            ->whereNotNull('environment_id')
            ->whereNotNull('data->pendingClose')
            ->when($teamId !== null, static fn ($query) => $query->where('team_id', $teamId))
            ->orderBy('id')
            ->limit(100)
            ->get();

        foreach ($pending as $preview) {
            $close = is_array($preview->data['pendingClose'] ?? null) ? $preview->data['pendingClose'] : [];
            $status = PreviewStatus::tryFrom(is_string($close['to'] ?? null) ? $close['to'] : '') ?? PreviewStatus::Closed;

            try {
                $this->destroy->handle($preview, $status, is_string($close['reason'] ?? null) ? $close['reason'] : 'The pull request was closed.');
                $touched++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $preparing = Preview::query()
            ->whereIn('status', [PreviewStatus::Preparing->value])
            ->whereNotNull('environment_id')
            ->when($teamId !== null, static fn ($query) => $query->where('team_id', $teamId))
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($preparing as $preview) {
            try {
                $this->deploy->handle($preview);
                $touched++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $projects = Preview::query()
            ->where('status', PreviewStatus::Queued->value)
            ->whereNull('environment_id')
            ->when($teamId !== null, static fn ($query) => $query->where('team_id', $teamId))
            ->distinct()
            ->pluck('project_id');

        foreach ($projects as $projectId) {
            $project = Project::query()->whereKey($projectId)->first();

            if (! $project instanceof Project) {
                continue;
            }

            $settings = PreviewSettings::forProject($project);
            $queued = Preview::query()->where('project_id', $project->id)->where('status', PreviewStatus::Queued->value)->whereNull('environment_id')->orderBy('id')->get();

            foreach ($queued as $preview) {
                if (! $this->gate->hasFreeSlot($project, $settings)) {
                    break;
                }

                try {
                    $touched += $this->start->handle($preview) ? 1 : 0;
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return $touched;
    }
}
