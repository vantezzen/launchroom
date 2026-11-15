<?php

declare(strict_types=1);

namespace App\Actions\Previews;

use App\Enums\Git\GitProviderKind;
use App\Enums\Previews\PreviewStatus;
use App\Models\Previews\Preview;
use App\Services\Git\GitProviders;
use Throwable;

/**
 * Nightly orphan sweep (docs/06 §5): open GitHub previews whose pull request
 * is closed on its provider (the `closed` webhook was missed) are torn down.
 * Unknown answers (API errors, uninstalled app) leave the preview alone.
 * Returns the number of previews closed.
 */
final readonly class SweepOrphanPreviews
{
    public function __construct(
        private GitProviders $providers,
        private DestroyPreview $destroy,
    ) {}

    public function handle(): int
    {
        $closed = 0;
        $open = Preview::query()
            ->whereIn('provider', [GitProviderKind::GitHub->value, GitProviderKind::GitLab->value, GitProviderKind::Gitea->value])
            ->whereNotIn('status', [PreviewStatus::Closed->value, PreviewStatus::Expired->value])
            ->orderBy('id')
            ->limit(1000)
            ->get();

        foreach ($open as $preview) {
            if ($this->providers->for($preview->provider)->pullRequestOpen($preview) !== false) {
                continue;
            }

            try {
                $this->destroy->handle($preview, PreviewStatus::Closed, "The pull request is closed on {$preview->provider->label()} (found by the nightly sweep).");
                $closed++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $closed;
    }
}
