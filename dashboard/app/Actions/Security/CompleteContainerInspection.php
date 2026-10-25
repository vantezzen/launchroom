<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Data\Protocol\V1\ContainerInspectionResult;
use App\Enums\Security\InvestigationStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\DaemonTask;
use App\Models\Security\SecurityInvestigation;
use App\Services\Crypto\ProjectKeyring;

/**
 * Stores one replica's container inspection (TaskCompletion.
 * containerInspection, docs/22 §7.2) with its investigation, encrypted
 * under the project DEK: process commands can carry secrets the masking
 * missed. Never in `daemon_tasks.result`. Runs inside CompleteTask's
 * transaction; the report is built once every replica is done
 * (SecurityTaskFinished).
 */
final readonly class CompleteContainerInspection
{
    public function __construct(
        private ProjectKeyring $keyring,
    ) {}

    /**
     * @throws DomainRuleViolation when no running investigation waits for the task
     */
    public function handle(DaemonTask $task, ContainerInspectionResult $result): SecurityInvestigation
    {
        $investigation = SecurityInvestigation::query()
            ->where('team_id', $task->team_id)
            ->where('status', InvestigationStatus::Running->value)
            ->whereRaw('replicas @> ?::jsonb', [json_encode([['task' => $task->public_id]], JSON_THROW_ON_ERROR)])
            ->with('project')
            ->lockForUpdate()
            ->first();

        if (! $investigation instanceof SecurityInvestigation) {
            throw DomainRuleViolation::conflict('No investigation is waiting for this inspection.');
        }

        $replicas = $investigation->replicas;
        $found = false;

        foreach ($replicas as $i => $replica) {
            if (($replica['task'] ?? null) === $task->public_id) {
                if (! hash_equals($replica['workload'], $result->workload)) {
                    throw DomainRuleViolation::conflict('The inspection is of another workload than the task asked for.');
                }

                $replicas[$i]['state'] = 'done';
                $found = true;
            }
        }

        if (! $found) {
            throw DomainRuleViolation::conflict('No investigation is waiting for this inspection.');
        }

        $project = $investigation->project;
        $stored = [];

        if (is_string($investigation->inspections)) {
            $decoded = json_decode($this->keyring->decryptInspections($project, $investigation->public_id, $investigation->inspections), true);
            $stored = is_array($decoded) ? $decoded : [];
        }

        $stored[$result->workload] = $result->toProtocol();

        $investigation->forceFill([
            'replicas' => $replicas,
            'inspections' => $this->keyring->encryptInspections($project, $investigation->public_id, json_encode($stored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ])->save();

        return $investigation;
    }
}
