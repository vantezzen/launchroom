<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\ProjectIconMode;
use App\Models\Project;
use App\Queries\ProjectPrimaryDomain;
use App\Services\Projects\FaviconFetcher;
use App\Services\Projects\ProjectIconStore;
use Illuminate\Support\Facades\DB;

/**
 * Looks up the favicon of a project in automatic mode (docs/08 §13) from its
 * primary production domain and stores it when one is found. A lookup that
 * finds nothing keeps the icon we already have (a site being down is no
 * reason to drop it). Runs in FetchProjectIconJob, never in a request.
 */
final readonly class RefreshProjectIcon
{
    public function __construct(
        private ProjectPrimaryDomain $domains,
        private FaviconFetcher $fetcher,
        private ProjectIconStore $store,
    ) {}

    public function handle(Project $project): bool
    {
        if ($project->icon_mode !== ProjectIconMode::Automatic) {
            return false;
        }

        $host = $this->domains->for($project);
        $found = $host === null ? null : $this->fetcher->fetch($host);
        $columns = $found === null ? null : $this->store->put($project, $found['icon']);

        $stored = DB::transaction(static function () use ($project, $found, $columns): bool {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->first();

            // Switched to an upload (or none) while we were fetching.
            if (! $project instanceof Project || $project->icon_mode !== ProjectIconMode::Automatic) {
                return false;
            }

            $project->forceFill($found === null || $columns === null
                ? ['icon_checked_at' => now()]
                : [...$columns, 'icon_source' => mb_substr($found['url'], 0, 255), 'icon_checked_at' => now()])->save();

            return $columns !== null;
        });

        $fresh = $project->fresh();

        if ($fresh instanceof Project) {
            $this->store->prune($fresh);
        }

        return $stored;
    }
}
