<?php

declare(strict_types=1);

namespace App\Data\Security;

use App\Enums\Security\WafMode;
use App\Support\Protocol\Limits;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * A service instance's firewall mode, paranoia and request body limit in
 * KiB (docs/22 §6.2, §6.7); each may be left out.
 */
final class SetWafSettingsData extends Data
{
    public function __construct(
        public WafMode|Optional $mode = new Optional,
        #[In([1, 2])]
        public int|Optional $paranoia = new Optional,
        #[In(Limits::WAF_INSPECT_BODY_KIB)]
        public int|Optional $inspectBodyKiB = new Optional,
    ) {}
}
