<?php

declare(strict_types=1);

namespace App\Services\ConfigAsCode;

use App\Data\ConfigAsCode\StagedConfigFileData;
use App\Data\Specs\ChangeItem;
use App\Data\Specs\EnvironmentSpec;
use App\Enums\ConfigAsCode\ConfigFileStatus;
use App\Models\ConfigAsCode\EnvironmentConfigFile;
use App\Models\Environment;

/**
 * Which staged changes launchroom.json manages (docs/18 §3.5), by item path
 * (`services.<svc>.build.buildCommand`, `services.<svc>.variables.<var>`,
 * `services.<svc>.domains.<hostname>`).
 */
final class ManagedPaths
{
    private const array FIELDS = [
        'branchOverride' => 'branch',
        'scaling.placementLabels' => 'scaling.placement.labels',
        'scaling.strategy' => 'scaling.placement.strategy',
    ];

    /**
     * @param  list<ChangeItem>  $items
     */
    public static function staged(Environment $environment, EnvironmentSpec $current, array $items): ?StagedConfigFileData
    {
        $record = EnvironmentConfigFile::query()->where('environment_id', $environment->id)->first();

        if (! $record instanceof EnvironmentConfigFile || $record->status === ConfigFileStatus::Missing) {
            return null;
        }

        $paths = [];

        foreach ($items as $item) {
            if (self::manages($record, $current, $item->path)) {
                $paths[] = $item->path;
            }
        }

        $waiting = $record->status === ConfigFileStatus::Staged && $items !== [];

        if (! $waiting && $paths === [] && $record->status !== ConfigFileStatus::Invalid) {
            return null;
        }

        return new StagedConfigFileData($record->path, $record->commit_sha, $record->status, $waiting, $paths);
    }

    public static function manages(EnvironmentConfigFile $record, EnvironmentSpec $current, string $path): bool
    {
        $segments = explode('.', $path);

        if ($segments[0] !== 'services' || count($segments) < 3) {
            return false;
        }

        $managed = $record->managed->service($segments[1]);

        if ($managed === null) {
            return false;
        }

        $rest = array_slice($segments, 2);

        return match ($rest[0]) {
            'variables' => in_array($current->service($segments[1])?->variables[$rest[1] ?? '']?->key, $managed->variables, true),
            'domains' => in_array(implode('.', array_slice($rest, 1)), $managed->domains, true),
            default => $managed->manages(self::FIELDS[implode('.', $rest)] ?? implode('.', $rest)),
        };
    }
}
