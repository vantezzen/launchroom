<?php

declare(strict_types=1);

namespace App\Data\ConfigAsCode;

use Spatie\LaravelData\Data;

/**
 * The launchroom.json problems a push failed on (docs/18 §6), for the
 * deployment page's FailureCard.
 */
final class DeploymentConfigErrorsData extends Data
{
    /**
     * @param  list<ConfigFileProblemData>  $errors
     */
    public function __construct(
        public string $path,
        public ?string $commitSha,
        public ?string $repository,
        public array $errors,
    ) {}

    /**
     * @param  array<string, mixed>|null  $stored  `deployments.config_errors`
     */
    public static function fromStored(?array $stored): ?self
    {
        if ($stored === null || ! is_string($stored['path'] ?? null)) {
            return null;
        }

        $errors = [];

        foreach (is_array($stored['errors'] ?? null) ? $stored['errors'] : [] as $row) {
            if (is_array($row)) {
                $errors[] = ConfigFileProblemData::from($row);
            }
        }

        return new self(
            $stored['path'],
            is_string($stored['commitSha'] ?? null) ? $stored['commitSha'] : null,
            is_string($stored['repository'] ?? null) ? $stored['repository'] : null,
            $errors,
        );
    }
}
