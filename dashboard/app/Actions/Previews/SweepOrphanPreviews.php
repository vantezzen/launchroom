<?php

declare(strict_types=1);

namespace App\Actions\Previews;

use App\Enums\Previews\PreviewProvider;
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
            ->whereIn('provider', [PreviewProvider::GitHub->value, PreviewProvider::GitLab->value, PreviewProvider::Gitea->value])
            ->whereNotIn('status', [PreviewStatus::Closed->value, PreviewStatus::Expired->value])
            ->orderBy('id')
            ->limit(1000)
            ->get();

        foreach ($open as $preview) {
            if ($this->providers->for($preview->provider->gitProvider())->pullRequestOpen($preview) !== false) {
                continue;
            }

            try {
                $this->destroy->handle($preview, PreviewStatus::Closed, "The pull request is closed on {$preview->provider->gitProvider()->label()} (found by the nightly sweep).");
                $closed++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $closed;
    }
}
