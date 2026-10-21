<?php

declare(strict_types=1);

namespace App\Enums\ConfigAsCode;

/**
 * The outcome of the last read of an environment's launchroom.json
 * (docs/18 §8).
 */
enum ConfigFileStatus: string
{
    /** Changes were applied and deployed with the push. */
    case Applied = 'applied';

    /** Changes were applied to the intent and wait for Deploy. */
    case Staged = 'staged';

    /** The file matches the environment: nothing to change. */
    case Unchanged = 'unchanged';

    /** The file has problems: nothing was applied, the push failed. */
    case Invalid = 'invalid';

    /** There is no file at that commit: nothing is managed. */
    case Missing = 'missing';

    public function label(): string
    {
        return match ($this) {
            self::Applied => 'Applied',
            self::Staged => 'Staged',
            self::Unchanged => 'Up to date',
            self::Invalid => 'Has problems',
            self::Missing => 'No file',
        };
    }
}
