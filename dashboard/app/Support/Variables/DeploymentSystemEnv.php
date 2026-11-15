<?php

declare(strict_types=1);

namespace App\Support\Variables;

/**
 * The variables that belong to one deployment rather than to the
 * environment (docs/06 §3.1): added when its env snapshot is stored, stripped
 * when a snapshot is carried to another deployment.
 */
final class DeploymentSystemEnv
{
    public const array KEYS = ['LAUNCHROOM_DEPLOYMENT_ID', 'LAUNCHROOM_GIT_COMMIT_SHA'];

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    public static function add(array $env, string $deploymentPublicId, ?string $commitSha): array
    {
        $env = self::strip($env);
        $env['LAUNCHROOM_DEPLOYMENT_ID'] = $deploymentPublicId;

        if ($commitSha !== null) {
            $env['LAUNCHROOM_GIT_COMMIT_SHA'] = $commitSha;
        }

        return $env;
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    public static function strip(array $env): array
    {
        return array_diff_key($env, array_flip(self::KEYS));
    }
}
