<?php

declare(strict_types=1);

namespace App\Data\Security;

use App\Enums\Security\Severity;
use Spatie\LaravelData\Data;

/**
 * One advisory of a fix group (docs/22 §9.2 part 2), opened on the
 * finding of its first affected service.
 */
final class FixGroupAdvisoryData extends Data
{
    public function __construct(
        public string $id,
        public ?string $title,
        public Severity $severity,
        public ?float $cvss,
        public bool $knownExploited,
        public ?string $fixedVersion,
        public int $services,
        public string $finding,
    ) {}
}
