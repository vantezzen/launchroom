<?php

declare(strict_types=1);

namespace App\Services\Previews;

use App\Data\Previews\PullRequestData;
use App\Enums\EnvironmentKind;
use App\Enums\Previews\ForkPolicy;
use App\Enums\Previews\PreviewStatus;
use App\Models\Environment;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewSettings;
use App\Models\Project;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Str;

/**
 * Whether a pull request gets a preview (docs/06 §2 "Pull request", §5,
 * docs/09 R20), and from which base environment.
 */
final class PreviewGate
{
    /**
     * Null: allowed. Otherwise the status to hold the preview in and why.
     *
     * @return array{PreviewStatus, string}|null
     */
    public function decide(PreviewSettings $settings, Preview $preview): ?array
    {
        if (! $settings->enabled) {
            return [PreviewStatus::Skipped, 'Previews are off for this project.'];
        }

        if (preg_match('/\[(skip preview|preview skip|no preview)\]/i', $preview->title) === 1) {
            return [PreviewStatus::Skipped, 'The pull request title asks to skip the preview ([skip preview]).'];
        }

        $targets = $settings->targetBranchPatterns();

        if ($targets !== [] && ! array_any($targets, static fn (string $glob): bool => Str::is($glob, $preview->base_ref))) {
            return [PreviewStatus::Skipped, "Previews only run for pull requests into {$settings->target_branches}."];
        }

        if ($settings->required_label !== null && $settings->required_label !== '' && ! in_array($settings->required_label, $preview->labels, true)) {
            return [PreviewStatus::Skipped, "Previews only run for pull requests labelled “{$settings->required_label}”."];
        }

        if ($preview->is_fork) {
            if ($settings->fork_policy === ForkPolicy::Off) {
                return [PreviewStatus::Skipped, 'Pull requests from forks get no previews in this project.'];
            }

            if (! PullRequestData::hasWriteAccess($preview->author_permission) && $preview->approved_sha !== $preview->head_sha) {
                return [PreviewStatus::AwaitingApproval, $preview->approved_sha === null
                    ? 'A maintainer has to approve previews of this fork.'
                    : 'New commits from the fork need a maintainer’s approval.'];
            }
        }

        if (! $this->baseEnvironment($preview->project, $settings) instanceof Environment) {
            return [PreviewStatus::Skipped, 'The base environment has never been deployed.'];
        }

        return null;
    }

    /**
     * The configured base, else `staging` if it exists, else production;
     * only environments that were deployed (they hold the intent to copy and
     * the images focused previews reuse).
     */
    public function baseEnvironment(Project $project, PreviewSettings $settings): ?Environment
    {
        $candidates = $settings->base_environment_id !== null
            ? $project->environments()->whereKey($settings->base_environment_id)->where('kind', '!=', EnvironmentKind::Preview->value)->get()
            : $project->environments()
                ->where(static fn (Builder $query) => $query->where('kind', EnvironmentKind::Production->value)->orWhere(static fn (Builder $staging) => $staging->where('kind', EnvironmentKind::Persistent->value)->where('slug', 'staging')))
                ->orderByRaw("case kind when 'persistent' then 0 else 1 end")
                ->get();

        foreach ($candidates as $environment) {
            if ($environment->last_applied_revision_id !== null) {
                return $environment;
            }
        }

        return null;
    }

    public function hasFreeSlot(Project $project, PreviewSettings $settings, ?Preview $except = null): bool
    {
        $running = Preview::query()
            ->where('project_id', $project->id)
            ->whereNotNull('environment_id')
            ->when($except instanceof Preview, static fn ($query) => $query->whereKeyNot((int) $except?->id))
            ->count();

        return $running < max(1, $settings->max_concurrent);
    }
}
