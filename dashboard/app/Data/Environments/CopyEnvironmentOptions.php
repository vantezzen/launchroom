<?php

declare(strict_types=1);

namespace App\Data\Environments;

use Spatie\LaravelData\Data;

/**
 * Options of App\Actions\Environments\CopyEnvironmentIntent (docs/06 §1, §5).
 */
final class CopyEnvironmentOptions extends Data
{
    /**
     * @param  list<string>|null  $serviceIds  service public ids to copy (null: all)
     */
    public function __construct(
        /** Copy secret values (fork previews get none, docs/06 §5). */
        public bool $secrets = true,
        /** Copy the variables targeted at the source environment. */
        public bool $variables = true,
        public ?array $serviceIds = null,
        /**
         * Record the copy as an environment promotion (kind `created`), the
         * base of the first Compare & promote between the two (docs/06 §6.3).
         */
        public bool $recordBase = true,
    ) {}
}
