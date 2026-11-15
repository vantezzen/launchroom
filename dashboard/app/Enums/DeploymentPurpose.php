<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a deployment is for. Only a `Release` can go live; the other two run
 * part of the pipeline for a neighbouring workflow and never route traffic.
 */
enum DeploymentPurpose: string
{
    case Release = 'release';
    /**
     * Builds the image for another platform ahead of a server move (docs/14
     * §13.3): ends as superseded once the image exists; the move's own
     * deployment reuses it (same build key). `deployments.target_platform`.
     */
    case Prebuild = 'prebuild';
    /**
     * Starts a never-deployed database alone so data can be imported before
     * the first deploy (docs/14 §12.9): staged, never promoted, kept until
     * `deployments.prepared_until`.
     */
    case PrepareImport = 'prepare_import';

    public function goesLive(): bool
    {
        return $this === self::Release;
    }
}
