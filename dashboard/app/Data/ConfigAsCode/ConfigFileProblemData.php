<?php

declare(strict_types=1);

namespace App\Data\ConfigAsCode;

use Spatie\LaravelData\Data;

/**
 * A problem in a launchroom.json (docs/18 §6): where it is (1-based line and
 * column, the dotted settings path users see), what is wrong, what to do,
 * and a few lines of the file around it. Excerpts never contain a value the
 * check refused as secret-looking (it is masked before it is stored).
 */
final class ConfigFileProblemData extends Data
{
    /**
     * @param  list<ConfigExcerptLineData>  $excerpt
     */
    public function __construct(
        public string $message,
        public ?int $line = null,
        public ?int $column = null,
        /** `services.web.deploy.port`; null for the whole file. */
        public ?string $path = null,
        public ?string $hint = null,
        public array $excerpt = [],
    ) {}

    public function where(): string
    {
        return $this->line === null ? '' : "line {$this->line}, column {$this->column}";
    }
}
