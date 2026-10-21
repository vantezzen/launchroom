<?php

declare(strict_types=1);

namespace App\Actions\Previews;

use App\Enums\Previews\PreviewStatus;
use App\Models\Environment;
use App\Models\Previews\Preview;
use App\Models\Previews\PreviewSettings;
use App\Services\Previews\PreviewGate;
use Illuminate\Support\Facades\DB;

/**
 * Gives an allowed pull request its environment (when a slot is free) and
 * starts deploying it. Re-checks the gate under the preview's row lock, so
 * a stale queue entry or a revoked approval doesn't start anything.
 * Returns whether the preview was started.
 */
final readonly class StartPreview
{
    public function __construct(
        private PreviewGate $gate,
        private CreatePreviewEnvironment $createEnvironment,
        private DeployPreview $deploy,
    ) {}

    public function handle(Preview $preview): bool
    {
        $started = DB::transaction(function () use ($preview): bool {
            $preview = Preview::query()->whereKey($preview->id)->with('project')->lockForUpdate()->first();

            if (! $preview instanceof Preview || ! $preview->status->isOpen() || $preview->environment_id !== null) {
                return false;
            }

            $settings = PreviewSettings::forProject($preview->project);
            $decision = $this->gate->decide($settings, $preview);

            if ($decision !== null) {
                $preview->transitionTo(...$decision);

                return false;
            }

            // Serializes slot accounting per project.
            PreviewSettings::query()->whereKey($settings->id)->lockForUpdate()->first();

            if (! $this->gate->hasFreeSlot($preview->project, $settings, $preview)) {
                $preview->transitionTo(PreviewStatus::Queued, "Waiting for a free preview slot ({$settings->max_concurrent} running in this project).");

                return false;
            }

            $base = $this->gate->baseEnvironment($preview->project, $settings);

            if (! $base instanceof Environment) {
                $preview->transitionTo(PreviewStatus::Skipped, 'The base environment has never been deployed.');

                return false;
            }

            $this->createEnvironment->handle($preview, $base);
            $preview->transitionTo(PreviewStatus::Preparing, 'Creating the environment.');

            return true;
        });

        if ($started) {
            $this->deploy->handle($preview);
        }

        return $started;
    }
}
