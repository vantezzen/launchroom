<?php

declare(strict_types=1);

namespace App\Data\Moves;

use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Models\Moves\ControlPlaneMove;
use App\Models\Server;
use App\Services\Moves\MovePlanner;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * Settings → Move launchroom (docs/14 §13.4) as the page shows it. The
 * token, anything sealed and the comparison code never appear here (the owner types the code from the new machine's terminal);
 * while it waits for approval.
 */
final class ControlPlaneMoveData extends Data
{
    public function __construct(
        public string $id,
        public ControlPlaneMoveStatus $status,
        public ?string $code,
        public ?string $receiverHostname,
        public ?string $receiverArch,
        public ?string $receiverAddress,
        public ?CarbonInterface $receiverSeenAt,
        /** The new machine's installer asked within the last 90 seconds. */
        public bool $receiverWaiting,
        public ?MoveServerData $source,
        public ?MoveServerData $target,
        public ?string $error,
        public ?CarbonInterface $tokenExpiresAt,
        public ?CarbonInterface $frozenAt,
        public ?CarbonInterface $handedOverAt,
        public ?string $newUrl,
        /** Size of the final dump once it is ready. */
        public ?string $dumpSize,
    ) {}

    public static function fromMove(ControlPlaneMove $move): self
    {
        $move->loadMissing(['source', 'target']);
        $receiver = $move->receiver ?? [];
        $size = $move->handover['database']['sizeBytes'] ?? null;

        return new self(
            $move->public_id,
            $move->status,
            // Never shown: the owner types it from the new machine's terminal,
            // which is what proves the claim came from that machine.
            null,
            is_string($receiver['hostname'] ?? null) ? $receiver['hostname'] : null,
            is_string($receiver['arch'] ?? null) ? $receiver['arch'] : null,
            is_string($receiver['address'] ?? null) ? $receiver['address'] : null,
            $move->receiver_seen_at,
            $move->receiver_seen_at?->gt(now()->subSeconds(90)) === true,
            $move->source instanceof Server ? MoveServerData::fromServer($move->source) : null,
            $move->target instanceof Server ? MoveServerData::fromServer($move->target) : null,
            $move->error,
            $move->status === ControlPlaneMoveStatus::WaitingForServer ? $move->token_expires_at : null,
            $move->frozen_at,
            $move->handed_over_at,
            $move->new_url,
            is_int($size) ? MovePlanner::size($size) : null,
        );
    }
}
