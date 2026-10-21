<?php

declare(strict_types=1);

namespace App\Data\Previews;

use App\Enums\PreviewStrategy;
use Spatie\LaravelData\Data;

/**
 * A stateful service of the base environment and its "In previews" setting.
 */
final class PreviewServiceData extends Data
{
    /**
     * @param  list<string>|null  $sanitizeCommand
     * @param  list<CloneCandidateData>  $candidates
     */
    public function __construct(
        public string $serviceId,
        public string $name,
        public string $engine,
        public PreviewStrategy $strategy,
        public ?string $cloneSourceId,
        public ?array $sanitizeCommand,
        public array $candidates,
    ) {}
}
