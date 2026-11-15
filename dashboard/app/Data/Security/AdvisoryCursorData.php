<?php

declare(strict_types=1);

namespace App\Data\Security;

use Spatie\LaravelData\Data;

/**
 * `advisory_feeds.cursor`: where the OSV sync resumes, the key
 * (ecosystem, name, version) of the last package it asked about.
 */
final class AdvisoryCursorData extends Data
{
    /**
     * @param  list<string>|null  $after  ecosystem, name, version
     */
    public function __construct(
        public ?array $after = null,
    ) {}
}
