<?php

declare(strict_types=1);

namespace App\Data\Api;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

/**
 * `GET /projects/{project}/previews`: one pull request's preview (`pr`), or
 * the newest previews (open ones first, `open` for only those).
 */
final class ListPreviewsQuery extends Data
{
    public function __construct(
        /** The pull or merge request number. */
        #[Min(1), Max(2147483647)]
        public ?int $pr = null,
        public bool $open = false,
    ) {}
}
