<?php

declare(strict_types=1);

namespace App\Data\Sessions;

use App\Enums\Sessions\InteractiveSessionKind;
use App\Enums\Sessions\InteractiveSessionStatus;
use Spatie\LaravelData\Data;

/**
 * One session in a tab's "Recent sessions" list.
 */
final class SessionHistoryItemData extends Data
{
    public function __construct(
        public string $id,
        public InteractiveSessionKind $kind,
        public InteractiveSessionStatus $status,
        public string $userName,
        public bool $isMine,
        public string $replicaLabel,
        public ?string $serverName,
        public ?string $shell,
        public string $openedAt,
        public ?string $endedAt,
        public ?int $durationSeconds,
        /** One calm sentence ("Ended after a while without input"); null while open. */
        public ?string $endReason,
        public ?int $exitCode,
        public bool $canEnd,
        public bool $hasRecording,
        /** Why the server couldn't start it ("This image has no shell…"), else null. */
        public ?string $failure = null,
    ) {}
}
