<?php

declare(strict_types=1);

namespace App\Enums\ConfigAsCode;

/**
 * Whose launchroom.json is being applied (docs/18 §5): a push to a branch
 * an environment deploys, a pull request of the same repository, or a
 * pull request from a fork.
 */
enum ConfigTrust: string
{
    case Trusted = 'trusted';
    case Preview = 'preview';
    case Fork = 'fork';

    public function isPreview(): bool
    {
        return $this !== self::Trusted;
    }
}
