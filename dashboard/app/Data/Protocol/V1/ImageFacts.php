<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

/**
 * What an image's config says about how it runs (protocol ImageFacts):
 * Report `workloads[].image` once pulled, BuildSummary `image` once built.
 * An empty user, "root" or "0" means the image starts as root.
 */
final class ImageFacts extends Data
{
    /**
     * @param  list<int>|null  $exposedPorts
     */
    public function __construct(
        #[Max(64)]
        public ?string $user = null,
        public ?array $exposedPorts = null,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['exposedPorts' => ['nullable', 'array', 'list', 'max:32'], 'exposedPorts.*' => ['integer', 'min:1', 'max:65535']];
    }

    public function startsAsRoot(): bool
    {
        return in_array(explode(':', $this->user ?? '')[0], ['', 'root', '0'], true);
    }
}
