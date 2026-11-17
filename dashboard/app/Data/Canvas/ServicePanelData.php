<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Data\Services\CronRunData;
use App\Enums\ServicePanelTab;
use App\Enums\ServiceSettingsSection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * The open service panel: which service and tab, plus the Settings tab's
 * data when that tab is open.
 */
final class ServicePanelData extends Data
{
    /**
     * @param  list<CronRunData>  $cronRuns
     */
    public function __construct(
        public string $serviceId,
        public ServicePanelTab $tab,
        public ?ServiceSettingsData $settings,
        public bool $canUpdate,
        public bool $canDelete,
        /** The Console tab is shown (containers.exec, docs/08 §15). */
        public bool $canConsole = false,
        /** The Data tab is shown (databases.query, databases only). */
        public bool $canData = false,
        /** Settings tab: the open page; null = the default. */
        public ?ServiceSettingsSection $section = null,
        /** Open "Action needed" findings of this service instance (docs/22 §9.1): the header chip. */
        public int $actionNeeded = 0,
        /** The finding the chip opens when there is exactly one (`fnd_…`); with more it opens the Security page for this service. */
        public ?string $actionNeededFinding = null,
        /** The header's Deploy, Restart, Stop and Start (D68). */
        public ?ServiceControlsData $controls = null,
        /** The header's source link: repository, image, engine or parent app. */
        public ?ServiceOriginData $origin = null,
        /** Restarts and the last exit of the live version, with the reason (G7). */
        public ?ServiceRuntimeFactsData $runtime = null,
        /** A cron service's recent runs, newest first (G19); empty for other kinds. */
        #[DataCollectionOf(CronRunData::class)]
        public array $cronRuns = [],
    ) {}
}
