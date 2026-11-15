<?php

declare(strict_types=1);

namespace App\Drivers\DatabaseEngines;

use App\Enums\DatabaseEngine;

/**
 * How an engine takes an import (docs/14 §12.3): the dump formats
 * launchroomd restores into it, the URL schemes it pulls from and how
 * versions compare. launchroomd reads the file again and decides; this is
 * the dashboard's early feedback.
 */
final readonly class DumpImport
{
    /**
     * @param  list<string>  $formats  protocol DataFormat values
     * @param  list<string>  $urlSchemes  the first is the one named in errors
     * @param  'tables'|'collections'  $objects  what the inspection counts
     * @param  string|null  $dumpTool  a dump tool whose own version must not be newer than the target
     * @param  int|null  $singleNumberMajorsFrom  first major version numbered without a minor (Postgres 10)
     * @param  string|null  $sqlFormat  the format SQL without a dump tool's header is imported as (null: refused)
     */
    public function __construct(
        public DatabaseEngine $engine,
        public array $formats,
        public string $formatsHint,
        public array $urlSchemes,
        public string $urlExample,
        public string $objects = 'tables',
        public ?string $dumpTool = null,
        public ?int $singleNumberMajorsFrom = null,
        public ?string $sqlFormat = null,
    ) {}

    /**
     * The comparable part of a version: Postgres majors ("17", "9.6"),
     * MySQL and MongoDB major.minor ("8.4", "7.0").
     */
    public function major(string $version): string
    {
        preg_match('/^([0-9]+)(?:\.([0-9]+))?/', $version, $m);
        $first = (int) ($m[1] ?? 0);
        $second = (int) ($m[2] ?? 0);

        if ($this->singleNumberMajorsFrom !== null && $first >= $this->singleNumberMajorsFrom) {
            return (string) $first;
        }

        return "{$first}.{$second}";
    }
}
