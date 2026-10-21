<?php

declare(strict_types=1);

namespace App\Support\Deployments\Failures;

/**
 * The regex match of a catalogue entry (empty when it matched by hint code).
 */
final readonly class FailureMatch
{
    /**
     * @param  array<array-key, string>  $groups
     */
    public function __construct(private array $groups = []) {}

    /** The text of a capture group ("" when absent). */
    public function group(int $index): string
    {
        return $this->groups[$index] ?? '';
    }

    /** The first non-empty capture group ("" when none). */
    public function firstGroup(): string
    {
        foreach ($this->groups as $key => $value) {
            if ($key !== 0 && is_int($key) && $value !== '') {
                return $value;
            }
        }

        return '';
    }
}
