<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Support\Images\ImageReference;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * A prebuilt image (`ghcr.io/umami-software/umami:postgresql-latest`). Workloads
 * run it digest-pinned from the local mirror; tags are resolved to a digest
 * at deploy time by App\Contracts\ImageDigestResolver.
 */
final class ImageSource extends Data implements ServiceSource
{
    public function __construct(
        #[Max(512)]
        public string $reference,
    ) {}

    public function type(): string
    {
        return 'image';
    }

    /**
     * @return array<string, list<string|\Closure>>
     */
    public static function rules(): array
    {
        return ['reference' => ['required', 'string', static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || ! ImageReference::tryParse($value) instanceof ImageReference) {
                $fail('The image reference is not valid (e.g. ghcr.io/org/app:1.2 or postgres@sha256:…).');
            }
        }]];
    }
}
