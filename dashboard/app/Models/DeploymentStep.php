<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeploymentStepName;
use App\Enums\DeploymentStepStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Timeline entry of a deployment (docs/07). `meta` holds step bookkeeping
 * (e.g. NodeState versions the route step waits for); never secrets.
 *
 * @property int $id
 * @property int $deployment_id
 * @property DeploymentStepName $name
 * @property DeploymentStepStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property array<string, mixed> $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['deployment_id', 'name', 'status', 'started_at', 'finished_at', 'meta'])]
final class DeploymentStep extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'meta' => '{}',
    ];

    /**
     * Starts (or restarts) a step of a deployment's timeline.
     */
    public static function begin(Deployment $deployment, DeploymentStepName $name): self
    {
        return self::query()->updateOrCreate(
            ['deployment_id' => $deployment->id, 'name' => $name],
            ['status' => DeploymentStepStatus::Running, 'started_at' => now(), 'finished_at' => null],
        );
    }

    /**
     * Finishes a step (creating it if it never started), merging $meta.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function finish(Deployment $deployment, DeploymentStepName $name, DeploymentStepStatus $status, array $meta = []): self
    {
        $step = self::query()->where('deployment_id', $deployment->id)->where('name', $name->value)->first();

        if ($step === null) {
            return self::query()->create(['deployment_id' => $deployment->id, 'name' => $name, 'status' => $status, 'started_at' => now(), 'finished_at' => now(), 'meta' => $meta]);
        }

        $step->forceFill(['status' => $status, 'finished_at' => now(), 'meta' => [...$step->meta, ...$meta]])->save();

        return $step;
    }

    /**
     * @return BelongsTo<Deployment, $this>
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => DeploymentStepName::class,
            'status' => DeploymentStepStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
