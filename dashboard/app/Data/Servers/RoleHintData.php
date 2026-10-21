<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\Servers\RoleHintTone;
use Spatie\LaravelData\Data;

/**
 * One contextual hint under "What should it do?" / "What this server does"
 * (docs/08 §14), from App\Support\Servers\RoleHints.
 */
final class RoleHintData extends Data
{
    public function __construct(
        public string $id,
        public RoleHintTone $tone,
        public string $title,
        public string $body,
        /** A page of the user docs for "Learn more" ("servers/home-machines", App\Support\DocsUrl). */
        public ?string $docs = null,
    ) {}
}
