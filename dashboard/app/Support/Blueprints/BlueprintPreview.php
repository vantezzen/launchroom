<?php

declare(strict_types=1);

namespace App\Support\Blueprints;

use App\Data\Blueprints\BlueprintBuild;
use App\Data\Blueprints\BlueprintDatabase;
use App\Data\Blueprints\BlueprintEdgeData;
use App\Data\Blueprints\BlueprintPreviewData;
use App\Data\Blueprints\BlueprintService;
use App\Data\Blueprints\BlueprintServicePreviewData;
use App\Data\Blueprints\BlueprintVariable;
use App\Data\Blueprints\BlueprintVariablePreviewData;
use App\Data\Blueprints\ProjectBlueprint;
use App\Data\Blueprints\ServiceChoiceData;
use App\Data\Services\ScalingSettings;
use App\Enums\ServiceKind;
use App\Support\Canvas\ServiceGlyph;
use App\Support\Variables\GeneratedValues;
use App\Support\Variables\VariableReferences;

/**
 * Turns a blueprint into the picture the import and template flows show
 * before anything exists (docs/17 §4). Variable values never leave: only
 * their keys and whether they are secret, generated or references.
 */
final class BlueprintPreview
{
    /**
     * @param  array<string, ServiceChoiceData>  $choices  by blueprint key
     */
    public static function of(ProjectBlueprint $blueprint, array $choices = []): BlueprintPreviewData
    {
        $defaults = new ScalingSettings;
        $services = [];
        $edges = [];
        $cpus = 0.0;
        $memory = 0;
        $volumes = 0;

        foreach ($blueprint->services as $service) {
            $replicas = $service->scaling->replicas ?? 1;
            $serviceCpus = $service->scaling->cpus ?? $defaults->cpus;
            $serviceMemory = $service->scaling->memoryMb ?? $defaults->memoryMb;
            $serviceVolumes = count($service->volumes) + ($service->kind === ServiceKind::Database ? 1 : 0);
            $cpus += $serviceCpus * $replicas;
            $memory += $serviceMemory * $replicas;
            $volumes += $serviceVolumes;

            $services[] = new BlueprintServicePreviewData(
                key: $service->key,
                name: $service->name,
                kind: $service->kind,
                logo: self::logo($service),
                subtitle: self::subtitle($service, $blueprint),
                group: match (true) {
                    $service->kind === ServiceKind::Database => 'data',
                    $service->public => 'public',
                    default => 'app',
                },
                port: $service->port,
                public: $service->public,
                replicas: $replicas,
                cpus: $serviceCpus,
                memoryMb: $serviceMemory,
                volumes: $serviceVolumes,
                variables: array_map(static fn (BlueprintVariable $variable): BlueprintVariablePreviewData => new BlueprintVariablePreviewData(
                    $variable->key,
                    $variable->secret,
                    GeneratedValues::contains($variable->value),
                    VariableReferences::parse($variable->value) !== [],
                ), $service->variables),
                notes: $service->notes,
                choice: $choices[$service->key] ?? null,
            );

            foreach ($service->connections as $connection) {
                $edges[] = new BlueprintEdgeData($service->key, $connection->to, 'connection');
            }

            if ($service->parent !== null) {
                $edges[] = new BlueprintEdgeData($service->parent, $service->key, 'process');
            }
        }

        return new BlueprintPreviewData($services, $edges, round($cpus, 2), $memory, $volumes, count($blueprint->sharedVariables));
    }

    private static function logo(BlueprintService $service): string
    {
        if ($service->logo !== null && $service->logo !== '') {
            return $service->logo;
        }

        return match ($service->kind) {
            ServiceKind::Database => $service->database->engine->value ?? 'postgres',
            ServiceKind::Image => ServiceGlyph::forImage((string) $service->image),
            ServiceKind::Worker => 'worker',
            ServiceKind::Cron => 'cron',
            ServiceKind::App => 'github',
        };
    }

    private static function subtitle(BlueprintService $service, ProjectBlueprint $blueprint): string
    {
        return match ($service->kind) {
            ServiceKind::Database => $service->database instanceof BlueprintDatabase ? 'Managed '.$service->database->engine->label().' '.$service->database->version : 'Database',
            ServiceKind::Image => ServiceGlyph::shortImage((string) $service->image),
            ServiceKind::Worker => 'Worker of '.($blueprint->service((string) $service->parent)->name ?? 'an app'),
            ServiceKind::Cron => 'Cron',
            ServiceKind::App => ! $service->build instanceof BlueprintBuild || $service->build->context === '' ? 'Built from your repository' : 'Built from ./'.$service->build->context,
        };
    }
}
