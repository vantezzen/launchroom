<?php

declare(strict_types=1);

namespace App\Data\Moves;

use App\Enums\Moves\MoveItemKind;
use App\Enums\Moves\MoveItemStatus;
use App\Models\Moves\ServerMoveItem;
use App\Models\Service;
use App\Models\ServiceInstance;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * Progress of one service in a move (docs/14 §13.2).
 */
final class ServerMoveItemData extends Data
{
    /**
     * @param  list<string>  $processes  workers and crons that stop with it
     */
    public function __construct(
        public string $id,
        public string $instanceId,
        public string $name,
        /** The service's own name and its "project / environment", for grouping. */
        public string $service,
        public string $scope,
        public MoveItemKind $kind,
        public MoveItemStatus $status,
        public ?string $failedStep,
        public ?string $message,
        public ?int $expectedBytes,
        public ?int $copiedBytes,
        public int $attempts,
        public ?CarbonInterface $stoppedAt,
        public ?CarbonInterface $startedAt,
        public ?CarbonInterface $finishedAt,
        public bool $canRetry,
        /** A failed service without data on the source can be left stopped (StopMoveItem). */
        public bool $canStop,
        public array $processes,
        /** The service's page. */
        public ?string $href,
    ) {}

    public static function fromItem(ServerMoveItem $item, bool $moveRunning): self
    {
        $instance = $item->serviceInstance;
        $canStop = $moveRunning && $item->status === MoveItemStatus::Failed && $item->kind === MoveItemKind::Stateless;
        $environment = $instance->environment;

        return new self(
            $item->public_id,
            $instance->public_id,
            "{$instance->service->name} ({$environment->project->name} / {$environment->name})",
            $instance->service->name,
            "{$environment->project->name} / {$environment->name}",
            $item->kind,
            $item->status,
            $item->failed_step,
            $item->message,
            $item->expected_bytes,
            $item->copied_bytes,
            $item->attempts,
            $item->stopped_at,
            $item->started_at,
            $item->finished_at,
            $moveRunning && $item->status === MoveItemStatus::Failed,
            $canStop,
            $canStop ? self::processes($instance) : [],
            $instance->removed_at === null ? route('projects.services.show', [$environment->project->public_id, $environment->public_id, $instance->service->public_id], absolute: false) : null,
        );
    }

    /**
     * The app's workers and crons in its environment (they stop with it).
     *
     * @return list<string>
     */
    private static function processes(ServiceInstance $instance): array
    {
        /** @var list<string> $names */
        $names = ServiceInstance::query()
            ->current()
            ->where('environment_id', $instance->environment_id)
            ->whereIn('service_id', Service::query()->select('id')->where('parent_service_id', $instance->service_id))
            ->with('service')
            ->orderBy('id')
            ->get()
            ->map(static fn (ServiceInstance $process): string => $process->service->name)
            ->values()
            ->all();

        return $names;
    }
}
