<?php

declare(strict_types=1);

namespace App\Jobs\Previews;

use App\Models\Previews\Preview;
use App\Services\Git\GitProviders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Reports a preview environment's aggregate state to its provider (GitHub:
 * one Deployment per preview, `inactive` after teardown; GitLab/Gitea: a
 * commit status and the PR comment, docs/19). Never fails the
 * preview: the reporter logs and swallows errors.
 */
#[Timeout(90)]
#[Tries(1)]
final class ReportPreviewToGitHubJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $previewId,
    ) {}

    public function handle(GitProviders $providers): void
    {
        $preview = Preview::query()->whereKey($this->previewId)->first();

        if ($preview instanceof Preview) {
            $providers->for($preview->provider->gitProvider())->reportPreview($preview);
        }
    }
}
