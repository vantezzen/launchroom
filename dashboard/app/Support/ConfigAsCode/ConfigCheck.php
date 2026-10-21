<?php

declare(strict_types=1);

namespace App\Support\ConfigAsCode;

use App\Data\ConfigAsCode\ConfigFileProblemData;

/**
 * The result of checking a launchroom.json: the file when it is usable, and
 * its errors and warnings (docs/18 §6).
 */
final readonly class ConfigCheck
{
    /**
     * @param  list<ConfigFileProblemData>  $errors
     * @param  list<ConfigFileProblemData>  $warnings
     */
    public function __construct(
        public ?ConfigFile $file,
        public array $errors = [],
        public array $warnings = [],
    ) {}

    public function valid(): bool
    {
        return $this->file instanceof ConfigFile && $this->errors === [];
    }

    /**
     * One line for a deployment's failure message and a commit status.
     */
    public function summary(string $path): string
    {
        $first = $this->errors[0] ?? null;

        if ($first === null) {
            return "{$path} is valid.";
        }

        $count = count($this->errors);
        $where = $first->line === null ? '' : " (line {$first->line}, column {$first->column})";

        return "{$path} has ".($count === 1 ? 'a problem' : "{$count} problems").": {$first->message}{$where}";
    }
}
