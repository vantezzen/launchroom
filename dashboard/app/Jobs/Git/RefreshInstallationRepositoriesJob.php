<?php

declare(strict_types=1);

namespace App\Jobs\Git;

use App\Actions\Git\RefreshInstallationRepositories;
use App\Models\Git\GitHubInstallation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;

#[Backoff([30, 120])]
#[Timeout(120)]
#[Tries(3)]
#[UniqueFor(300)]
final class RefreshInstallationRepositoriesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $installationId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->installationId;
    }

    public function handle(RefreshInstallationRepositories $refresh): void
    {
        $installation = GitHubInstallation::query()->with('app')->where('id', $this->installationId)->first();

        if ($installation instanceof GitHubInstallation) {
            $refresh->handle($installation);
        }
    }
}
