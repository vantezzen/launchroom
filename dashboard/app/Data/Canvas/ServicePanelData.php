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
        /** `deployments`, `metrics`, `logs`, `console`, `data` or `settings`. */
        public string $tab,
        public ?ServiceSettingsData $settings,
        public bool $canUpdate,
        public bool $canDelete,
        /** The Console tab is shown (containers.exec, docs/08 §15). */
        public bool $canConsole = false,
        /** The Data tab is shown (databases.query, databases only). */
        public bool $canData = false,
        /** Settings tab: the open page (`general`, `variables`, `domains`, `backups`…; null = the default). */
        public ?string $section = null,
        /** Open "Action needed" findings of this service instance (docs/22 §9.1): the header chip. */
        public int $actionNeeded = 0,
        /** The finding the chip opens when there is exactly one (`fnd_…`); with more it opens the Security page for this service. */
        public ?string $actionNeededFinding = null,
        /** The header's Deploy, Restart, Stop and Start (D68). */
        public ?ServiceControlsData $controls = null,
    ) {}
}
