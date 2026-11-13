<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * What attaching a volume to a service means, before anything is staged
 * (D73): why it can't (`problem`), what changes for the service
 * (`notices`, such as moving to the volume's server) and a mount path to
 * start from (the owner's).
 */
final class VolumeAttachPreviewData extends Data
{
    /**
     * @param  list<string>  $notices
     */
    public function __construct(
        public ?string $problem,
        public array $notices,
        public string $suggestedPath,
    ) {}
}
