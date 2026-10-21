<?php

declare(strict_types=1);

namespace App\Data\ConfigAsCode;

use App\Data\Specs\ChangeItem;
use Spatie\LaravelData\Data;

/**
 * A launchroom.json checked against an environment (docs/18 §7): its
 * problems and, for a dry run, the staged changes it would produce and what
 * the trust rules would ignore. Never carries variable values.
 */
final class ConfigCheckData extends Data
{
    /**
     * @param  list<ConfigFileProblemData>  $errors
     * @param  list<ConfigFileProblemData>  $warnings
     * @param  list<ConfigFileProblemData>  $ignored
     * @param  list<ChangeItem>  $changes
     */
    public function __construct(
        public bool $valid,
        public array $errors = [],
        public array $warnings = [],
        public array $ignored = [],
        public array $changes = [],
    ) {}
}
