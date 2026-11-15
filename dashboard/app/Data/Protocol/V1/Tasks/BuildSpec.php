<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Enums\DaemonTaskType;

/**
 * Builds and pushes one image (protocol BuildSpec, docs/04 §5).
 */
final class BuildSpec extends TaskSpec
{
    public function __construct(
        public BuildInputs $inputs,
        public string $image,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::Build;
    }

    public function requiredFeatures(): array
    {
        return $this->inputs->requiredFeatures();
    }

    public function platform(): string
    {
        return $this->inputs->platform;
    }

    public function platformEmulation(): bool
    {
        return true;
    }

    public function toProtocol(): array
    {
        return [...self::wire($this->inputs), 'image' => $this->image];
    }

    protected static function fromWire(array $wire): static
    {
        $image = $wire['image'] ?? null;

        return new self(BuildInputs::from($wire), is_string($image) ? $image : '');
    }
}
