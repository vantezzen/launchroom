<?php

declare(strict_types=1);

namespace App\Scheduling;

/**
 * A build server as the build router sees it (docs/04 §5).
 */
final readonly class BuildCandidate
{
    public function __construct(
        public int $id,
        /** `linux/amd64` / `linux/arm64`; null when unknown. */
        public ?string $platform,
        /** Build slots (`max(1, cores / 4)`, docs/04 §5). */
        public int $slots = 1,
        /** Build and plan tasks it is running now. */
        public int $running = 0,
        public bool $online = true,
        /**
         * Platforms it builds through the engine's emulation (D53: an Apple
         * Silicon Mac whose engine runs amd64 binaries through QEMU or
         * Rosetta), besides its own.
         *
         * @var list<string>
         */
        public array $emulated = [],
    ) {}

    /** It can build images for $platform (natively, emulated, or unknown platform). */
    public function builds(string $platform): bool
    {
        return $this->platform === null || $this->platform === $platform || in_array($platform, $this->emulated, true);
    }

    /** It builds $platform without emulation (or its platform is unknown). */
    public function native(string $platform): bool
    {
        return $this->platform === null || $this->platform === $platform;
    }

    public function load(): float
    {
        return $this->running / max(1, $this->slots);
    }

    public function hasFreeSlot(): bool
    {
        return $this->running < max(1, $this->slots);
    }
}
