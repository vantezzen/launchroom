<?php

declare(strict_types=1);

namespace App\Data\Moves;

use App\Enums\Moves\MoveItemKind;
use App\Enums\Moves\MoveItemStatus;
use App\Models\Moves\ServerMoveItem;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * Progress of one service in a move (docs/14 §13.2).
 */
final class ServerMoveItemData extends Data
{
    public function __construct(
        public string $id,
        public string $instanceId,
        public string $name,
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
    ) {}

    public static function fromItem(ServerMoveItem $item, bool $moveRunning): self
    {
        $instance = $item->serviceInstance;

        return new self(
            $item->public_id,
            $instance->public_id,
            "{$instance->service->name} ({$instance->environment->project->name} / {$instance->environment->name})",
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
        );
    }
}
