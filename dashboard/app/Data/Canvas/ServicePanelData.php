<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * The open service panel: which service and tab, plus the Settings tab's
 * data when that tab is open.
 */
final class ServicePanelData extends Data
{
    public function __construct(
        public string $serviceId,
        /** `deployments`, `variables`, `metrics`, `logs`, `console`, `data`, `backups` or `settings`. */
        public string $tab,
        public ?ServiceSettingsData $settings,
        public bool $canUpdate,
        public bool $canDelete,
        /** The Console tab is shown (containers.exec, docs/08 §15). */
        public bool $canConsole = false,
        /** The Data tab is shown (databases.query, databases only). */
        public bool $canData = false,
        /** Settings tab: the open page (`general`, `domains`, `scaling`…; null = the default). */
        public ?string $section = null,
    ) {}
}
