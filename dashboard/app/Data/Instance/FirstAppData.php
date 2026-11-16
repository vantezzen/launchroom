<?php

declare(strict_types=1);

namespace App\Data\Instance;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * The team's first app (docs/08 §3.1, flows §1): the first production
 * service that is live, or, before one is, the production deployment that
 * is running. Get started's step 4 and the first-live card on the canvas
 * show it.
 */
final class FirstAppData extends Data
{
    /**
     * @param  list<FirstAppStepData>  $steps  while deploying: Build, Start, Health check, Route
     */
    public function __construct(
        #[LiteralTypeScriptType("'deploying' | 'live'")]
        public string $state,
        public string $projectId,
        public string $projectName,
        public string $environmentId,
        public string $serviceId,
        public string $serviceName,
        public string $logo,
        /** The live address: the first custom domain, else the generated one. */
        public ?string $hostname = null,
        /** When the live version went live. */
        public ?CarbonInterface $liveSince = null,
        /** The running deployment ("Watch it"). */
        public ?string $deploymentId = null,
        public ?CarbonInterface $startedAt = null,
        public array $steps = [],
        /** Built from a public URL: pushes aren't observed, so "Deploy on every push" is offered. */
        public bool $suggestGit = false,
        /** The first-live card was dismissed (stored on the onboarding state). */
        public bool $seen = false,
    ) {}
}
