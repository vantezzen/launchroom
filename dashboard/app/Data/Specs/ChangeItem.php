<?php

declare(strict_types=1);

namespace App\Data\Specs;

use App\Enums\ChangeEffect;
use App\Enums\ChangeKind;
use App\Enums\PromotionClass;
use Spatie\LaravelData\Data;

/**
 * One staged change (docs/06 §4). `path` identifies the item for Discard
 * (`services.svc_….deploy.startCommand`, `services.svc_….variables.var_…`).
 * `before`/`after` are display strings and are never set for variables:
 * a diff never carries values (not even ciphertexts).
 */
final class ChangeItem extends Data
{
    public function __construct(
        public string $path,
        public ChangeKind $kind,
        public ChangeEffect $effect,
        public PromotionClass $promotion,
        public string $label,
        public ?string $serviceId = null,
        public ?string $before = null,
        public ?string $after = null,
    ) {}
}
