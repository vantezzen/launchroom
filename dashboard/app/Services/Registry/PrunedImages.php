<?php

declare(strict_types=1);

namespace App\Services\Registry;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Support\Images\AppImage;
use App\Support\Images\RegistryLayout;
use Illuminate\Support\Facades\Log;

/**
 * Marks deployments whose built image the registry no longer has
 * (`deployments.image_pruned_at`), so they are not offered for rollback
 * (docs/04 §8: Zot keeps the last N images per service, `apps/**`
 * retention). Scheduled; only definite answers count: a 404 for the
 * manifest digest marks the image pruned, any other outcome leaves it alone.
 *
 * Only images under {@see RegistryLayout::appsPrefix()} are checked: mirrored
 * upstream images can always be re-fetched by digest.
 */
final readonly class PrunedImages
{
    public function __construct(
        private RegistryManifests $manifests,
    ) {}

    /**
     * @return int deployments newly marked
     */
    public function markPruned(int $limit = 500): int
    {
        $prefix = RegistryLayout::appsPrefix();

        /** @var list<string> $images */
        $images = Deployment::query()
            ->whereNull('image_pruned_at')
            ->whereIn('status', [DeploymentStatus::Superseded->value, DeploymentStatus::Active->value])
            ->where('image', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%')
            ->distinct()
            ->limit($limit)
            ->pluck('image')
            ->all();

        $marked = 0;

        foreach ($images as $image) {
            $built = AppImage::parse($image);

            if ($built instanceof AppImage && $this->manifests->head($built->repository(), $built->digest) === false) {
                $marked += Deployment::query()->where('image', $image)->whereNull('image_pruned_at')->update(['image_pruned_at' => now()]);
            }
        }

        if ($marked > 0) {
            Log::info('Deployments whose images the registry pruned are no longer rollback targets.', ['deployments' => $marked]);
        }

        return $marked;
    }
}
