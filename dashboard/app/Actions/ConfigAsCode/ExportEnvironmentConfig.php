<?php

declare(strict_types=1);

namespace App\Actions\ConfigAsCode;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\ConfigAsCode\ConfigExportData;
use App\Enums\AuditAction;
use App\Models\Environment;
use App\Models\EnvironmentRevision;
use App\Queries\CurrentEnvironmentSpec;
use App\Services\ConfigAsCode\ConfigExporter;

/**
 * Download an environment as launchroom.json (docs/18 §4): the current
 * intent, or a deployed revision. Audited without values.
 */
final readonly class ExportEnvironmentConfig
{
    public function __construct(
        private CurrentEnvironmentSpec $currentSpec,
        private ConfigExporter $exporter,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Environment $environment, ?EnvironmentRevision $revision, AuditActor $actor): ConfigExportData
    {
        $environment->loadMissing('project');
        $spec = $revision instanceof EnvironmentRevision ? $revision->spec : $this->currentSpec->get($environment);
        $file = $this->exporter->export($environment->project, $spec);

        $this->audit->handle(new AuditEntryData(
            action: AuditAction::ConfigFileExported,
            actor: $actor,
            teamId: $environment->team_id,
            subjectType: 'environment',
            subjectId: $environment->public_id,
            meta: ['revision' => $revision?->number, 'services' => is_array($file['services']) ? count($file['services']) : 0],
        ));

        return new ConfigExportData($environment->project->settings->configFile->path, ConfigExporter::encode($file), $revision?->number);
    }
}
