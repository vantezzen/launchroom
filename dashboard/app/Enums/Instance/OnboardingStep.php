<?php

declare(strict_types=1);

namespace App\Enums\Instance;

/**
 * The first-run checklist (docs/08 §3.1), in the order of flows §1: the
 * first deploy comes before the optional backup. Stored skips in
 * `instance_settings.onboarding.skipped` hold these values.
 */
enum OnboardingStep: string
{
    case Server = 'server';
    case Github = 'github';
    case Domain = 'domain';
    case Project = 'project';
    case Backups = 'backups';

    public function skippable(): bool
    {
        return in_array($this, [self::Github, self::Domain, self::Backups], true);
    }

    /**
     * @return list<string>
     */
    public static function skippableValues(): array
    {
        return array_values(array_map(static fn (self $step): string => $step->value, array_filter(self::cases(), static fn (self $step): bool => $step->skippable())));
    }
}
