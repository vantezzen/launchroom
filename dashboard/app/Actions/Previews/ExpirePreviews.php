<?php

declare(strict_types=1);

namespace App\Actions\Previews;

use App\Enums\Previews\PreviewStatus;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewSettings;
use Throwable;

/**
 * Expiry after N days of inactivity (docs/06 §5): a preview whose pull
 * request saw no new commits (or reopen) for its project's `expiry_days` is
 * torn down. A new push to the PR starts it again. Returns the number expired.
 */
final readonly class ExpirePreviews
{
    public function __construct(
        private DestroyPreview $destroy,
    ) {}

    public function handle(): int
    {
        $expired = 0;
        $candidates = Preview::query()
            ->whereNotNull('environment_id')
            ->where('last_activity_at', '<', now()->subDay())
            ->oldest('last_activity_at')
            ->limit(500)
            ->get();

        foreach ($candidates as $preview) {
            $settings = PreviewSettings::forProject($preview->project);

            if ($settings->expiry_days < 1 || $preview->expiresAt($settings->expiry_days)->isFuture()) {
                continue;
            }

            try {
                $this->destroy->handle($preview, PreviewStatus::Expired, "No activity for {$settings->expiry_days} days. Push a commit to start it again.");
                $expired++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $expired;
    }
}
