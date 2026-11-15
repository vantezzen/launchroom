<?php

declare(strict_types=1);

namespace App\Support\ConfigAsCode;

use App\Data\Services\ScalingSettings;

/**
 * How the settings groups of launchroom.json (`build`, `deploy`, `scaling`)
 * map to the stored settings Data (docs/18 §3.2), in both directions: the
 * applier reads a file into settings, the exporter writes settings back.
 * Names are the same except scaling's `placement: {labels, strategy}`,
 * stored as `placementLabels` and `strategy`.
 */
final class SettingsMap
{
    /** @var array<string, string> stored scaling field => file path */
    private const array SCALING_PATHS = ['placementLabels' => 'placement.labels', 'strategy' => 'placement.strategy'];

    /**
     * A file's scaling group in ScalingSettings' names.
     *
     * @param  array<string, mixed>  $scaling
     * @return array<string, mixed>
     */
    public static function scalingFromFile(array $scaling): array
    {
        $placement = Arrays::assoc($scaling['placement'] ?? null);
        unset($scaling['placement']);

        foreach (self::SCALING_PATHS as $field => $path) {
            $key = substr($path, strlen('placement.'));

            if (array_key_exists($key, $placement)) {
                $scaling[$field] = $placement[$key];
            }
        }

        return $scaling;
    }

    /**
     * Scaling as the file writes it: only what differs from a new service's.
     *
     * @return array<string, mixed>
     */
    public static function scalingToFile(ScalingSettings $scaling): array
    {
        $defaults = new ScalingSettings;
        $out = self::changed(
            ['replicas' => $scaling->replicas, 'cpus' => $scaling->cpus, 'memoryMb' => $scaling->memoryMb],
            ['replicas' => $defaults->replicas, 'cpus' => $defaults->cpus, 'memoryMb' => $defaults->memoryMb],
        );
        $placement = [];

        if ($scaling->placementLabels !== []) {
            $labels = $scaling->placementLabels;
            ksort($labels);
            $placement['labels'] = $labels;
        }

        if ($scaling->strategy !== $defaults->strategy) {
            $placement['strategy'] = $scaling->strategy->value;
        }

        if ($placement !== []) {
            $out['placement'] = $placement;
        }

        return $out;
    }

    /**
     * Dotted file paths of stored settings values (`healthcheck.path`,
     * `placement.labels`); lists and label maps count as one leaf.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public static function leaves(array $values, string $prefix = ''): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            $key = self::SCALING_PATHS[$key] ?? (string) $key;

            if ($key === 'healthcheck' && is_array($value)) {
                array_push($out, ...self::leaves(Arrays::assoc($value), $prefix.'healthcheck.'));

                continue;
            }

            $out[] = $prefix.$key;
        }

        return $out;
    }

    /**
     * Stored settings with the file's values on top (`healthcheck` merged
     * field by field).
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function merge(array $current, array $values): array
    {
        foreach ($values as $key => $value) {
            $current[$key] = $key === 'healthcheck' && is_array($value) ? [...Arrays::assoc($current[$key] ?? null), ...Arrays::assoc($value)] : $value;
        }

        return $current;
    }

    /**
     * The entries of $current that differ from $defaults (floats compared loosely).
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $defaults
     * @param  list<string>  $skip
     * @return array<string, mixed>
     */
    public static function changed(array $current, array $defaults, array $skip = []): array
    {
        $out = [];

        foreach ($current as $key => $value) {
            if (in_array($key, $skip, true)) {
                continue;
            }

            $default = $defaults[$key] ?? null;
            $same = (is_float($value) || is_float($default)) && is_numeric($value) && is_numeric($default) ? abs((float) $value - (float) $default) < 1e-9 : $value === $default;

            if (! $same && ($value !== null || $default !== null)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
