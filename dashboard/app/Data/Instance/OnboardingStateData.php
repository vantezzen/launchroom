<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Spatie\LaravelData\Data;

/**
 * `instance_settings.onboarding`: the first-run checklist's stored choices
 * (docs/08 §3.1). The steps' state itself is derived (OnboardingChecklist).
 */
final class OnboardingStateData extends Data
{
    /**
     * @param  list<string>  $skipped  skipped App\Enums\Instance\OnboardingStep values
     * @param  list<int>  $twoFactorDismissed  users who chose "Not now" on the 2FA recommendation
     * @param  list<int>  $smallServerHintDismissed  users who dismissed "This server is small"
     */
    public function __construct(
        public ?string $dismissedAt = null,
        public array $skipped = [],
        public array $twoFactorDismissed = [],
        public array $smallServerHintDismissed = [],
        /** The platform domain the owner continued with and whether its DNS check passed then. */
        public ?DomainConfirmationData $domainConfirmed = null,
        /** The first-live card on the canvas was dismissed (ISO 8601). */
        public ?string $firstAppSeenAt = null,
    ) {}

    public function confirmed(?string $domain): bool
    {
        return $domain !== null && $domain !== '' && $this->domainConfirmed?->domain === $domain;
    }

    /**
     * Whether $domain's DNS check passed when the owner continued with it
     * (null: not confirmed, or confirmed before the check existed).
     */
    public function verified(?string $domain): ?bool
    {
        return $this->confirmed($domain) ? $this->domainConfirmed?->verified : null;
    }
}
