<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\Specs\EnvironmentSpec;
use App\Data\Specs\ServiceSpec;

/**
 * The build key (docs/04 §7): a hash of every build-affecting input. Equal
 * keys mean an existing image can be reused instead of rebuilding.
 *
 * Inputs: commit SHA, source (repository, root directory), build settings
 * (builder, overrides, Railpack version), branch override, and a keyed MAC of
 * the build-scoped variable values (so the key never makes secrets guessable).
 */
final class BuildKey
{
    /**
     * Keys of the variables a build of $service sees (docs/06 §3.3).
     *
     * @return list<string>
     */
    public static function buildScopedKeys(EnvironmentSpec $spec, ServiceSpec $service): array
    {
        $keys = [];

        foreach ([...$service->variables, ...$service->connectionVariables] as $variable) {
            if ($variable->exposure->includesBuild()) {
                $keys[] = $variable->key;
            }
        }

        foreach ($service->sharedVariableIds as $id) {
            $variable = $spec->sharedVariables[$id] ?? null;

            if ($variable !== null && $variable->exposure->includesBuild()) {
                $keys[] = $variable->key;
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    public static function compute(ServiceSpec $service, ?string $commitSha, string $buildSecretsMac): string
    {
        return hash('sha256', CanonicalJson::encode([
            'v' => 1,
            'commit' => $commitSha,
            'source' => $service->source,
            'build' => $service->build->toArray(),
            'branch' => $service->branchOverride,
            'secrets' => $buildSecretsMac,
        ]));
    }
}
