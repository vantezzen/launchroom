<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Deployments\EvaluateDeploymentChecksJob;
use App\Models\Deployment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:deployments:check-gates')]
#[Description('Read the CI checks of push deployments that wait for them (D111): the webhook fallback, and where timeouts end')]
final class CheckDeploymentGatesCommand extends Command
{
    public function handle(): int
    {
        /** @var list<int> $ids */
        $ids = Deployment::query()->waitingForChecks()->orderBy('id')->pluck('id')->all();

        foreach ($ids as $id) {
            dispatch(new EvaluateDeploymentChecksJob($id));
        }

        return self::SUCCESS;
    }
}
