<?php

declare(strict_types=1);

namespace App\Data\Registry;

use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * Settings → Registry (docs/14 §9.11): where Zot keeps images, and whether
 * the saved settings are in the config file Zot runs with.
 *
 * `applied` compares the rendered config with the last one written by
 * `launchroom:registry:config --write` (null: nothing written from here yet,
 * i.e. the installer's local-storage template is in use).
 */
final class RegistrySettingsData extends Data
{
    public function __construct(
        public ?RegistryStorageData $storage,
        public ?bool $applied,
        public ?CarbonInterface $writtenAt,
        public string $applyCommand,
    ) {}
}
