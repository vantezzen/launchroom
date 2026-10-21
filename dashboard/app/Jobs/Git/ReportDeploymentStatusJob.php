<?php

declare(strict_types=1);

namespace App\Jobs\Git;

use App\Data\Git\DeploymentStatusReport;
use App\Enums\Git\GitProviderKind;
use App\Services\Git\GitProviders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Reports a deployment status to its git provider off the request path. The reporter
 * swallows and logs failures, so this never fails a deployment.
 */
#[Timeout(90)]
#[Tries(1)]
final class ReportDeploymentStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly DeploymentStatusReport $report,
    ) {}

    public function handle(GitProviders $providers): void
    {
        $kind = GitProviderKind::tryFrom($this->report->provider);

        if ($kind !== null) {
            $providers->for($kind)->reportDeployment($this->report);
        }
    }
}
