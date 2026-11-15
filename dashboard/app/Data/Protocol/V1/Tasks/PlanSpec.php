<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1\Tasks;

use App\Enums\DaemonFeature;
use App\Enums\DaemonTaskType;

/**
 * A dry-run build plan (protocol PlanSpec). `inspect` also reports the
 * repository's files (docs/20 §2).
 */
final class PlanSpec extends TaskSpec
{
    public function __construct(
        public BuildInputs $inputs,
        public ?bool $inspect = null,
    ) {}

    public function type(): DaemonTaskType
    {
        return DaemonTaskType::Plan;
    }

    public function requiredFeatures(): array
    {
        $features = $this->inputs->requiredFeatures();

        if ($this->inspect === true && ! in_array(DaemonFeature::GeneratedDockerfile, $features, true)) {
            $features[] = DaemonFeature::GeneratedDockerfile;
        }

        return $features;
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
        return [...self::wire($this->inputs), ...($this->inspect === true ? ['inspect' => true] : [])];
    }

    protected static function fromWire(array $wire): static
    {
        return new self(BuildInputs::from($wire), ($wire['inspect'] ?? null) === true ? true : null);
    }
}
