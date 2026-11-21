<?php

declare(strict_types=1);

namespace App\Support\Images;

/**
 * An image a template pins (docs/17 §3.1): its tag and digest are the
 * version launchroom tested the template with.
 */
final readonly class CuratedPin
{
    public function __construct(
        public string $registry,
        public string $repository,
        public string $tag,
        public string $digest,
        public string $templateId,
        public string $templateName,
        public ?string $releases = null,
    ) {}
}
