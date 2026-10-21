<?php

declare(strict_types=1);

namespace App\Actions\Canvas;

use App\Data\Canvas\CanvasLayoutData;
use App\Models\CanvasLayout;
use App\Models\Environment;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Merges node positions into an environment's canvas layout. Applied
 * instantly, never staged (docs/06 §4). Positions of services that aren't
 * in the project are dropped.
 */
final class SaveCanvasLayout
{
    public function handle(Environment $environment, CanvasLayoutData $data): CanvasLayout
    {
        return DB::transaction(function () use ($environment, $data): CanvasLayout {
            $layout = CanvasLayout::query()->where('environment_id', $environment->id)->lockForUpdate()->first()
                ?? CanvasLayout::query()->create(['environment_id' => $environment->id]);

            /** @var list<string> $known */
            $known = Service::query()->where('project_id', $environment->project_id)->pluck('public_id')->all();
            $known = array_flip($known);
            $positions = array_intersect_key($layout->positions, $known);

            foreach ($data->positions as $serviceId => $position) {
                if (isset($known[$serviceId])) {
                    $positions[$serviceId] = ['x' => round($position->x, 1), 'y' => round($position->y, 1)];
                }
            }

            $layout->positions = $positions;
            $layout->save();

            return $layout;
        });
    }
}
