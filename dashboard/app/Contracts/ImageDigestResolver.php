<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Exceptions\ImageResolutionFailed;
use App\Support\Images\ImageReference;

/**
 * Resolves an image tag to a digest for image services (`umami:latest` →
 * `…@sha256:…`), so every workload is digest-pinned. Bound to
 * App\Services\Images\RegistryImageDigestResolver (a lookup through the
 * local Zot mirror, docs/04 §11).
 */
interface ImageDigestResolver
{
    /**
     * @throws ImageResolutionFailed
     */
    public function resolve(ImageReference $reference): ImageReference;
}
