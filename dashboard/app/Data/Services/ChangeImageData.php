<?php

declare(strict_types=1);

namespace App\Data\Services;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * Another image for an image service (project-wide, staged; docs/04 §1).
 * A tag or a digest: tags are pinned to a digest when the deploy starts
 * (D24). The grammar is the one every image source passes
 * ({@see ImageSource::rules()}).
 */
final class ChangeImageData extends Data
{
    public function __construct(
        #[Max(512)]
        public string $image,
    ) {}

    /**
     * @return array<string, list<string|\Closure>>
     */
    public static function rules(): array
    {
        return ['image' => ImageSource::rules()['reference']];
    }
}
