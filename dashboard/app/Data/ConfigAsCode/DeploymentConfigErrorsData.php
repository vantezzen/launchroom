<?php

declare(strict_types=1);

namespace App\Data\ConfigAsCode;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * `deployments.config_errors`: the launchroom.json problems a push failed on
 * (docs/18 §6), for the deployment page's FailureCard.
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
        #[DataCollectionOf(ConfigFileProblemData::class)]
        public array $errors,
    ) {}
}
