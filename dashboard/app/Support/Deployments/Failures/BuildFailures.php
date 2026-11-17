<?php

declare(strict_types=1);

namespace App\Support\Deployments\Failures;

use App\Data\Deployments\FailureExplanationData;
use App\Enums\Builds\BuildHintCode;
use App\Enums\DeploymentFailureClass;
use App\Enums\FailureFixAction;

/**
 * Catalogue entries for build failures the server caused or that need the
 * app changed (G17, G55): a full disk, names that don't resolve, a
 * prerender that queries the database, a build that went quiet, and the
 * modules and rate limits launchroomd recognises. Matched on launchroomd's
 * build hint codes only; names come from the log.
 *
 * @phpstan-import-type Entry from FailureCatalogue
 */
final class BuildFailures
{
    private const string HOST = '([a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,})';

    /**
     * @return list<Entry>
     */
    public static function entries(): array
    {
        $build = [DeploymentFailureClass::BuildError, DeploymentFailureClass::PlanError, DeploymentFailureClass::BuildTimeout, DeploymentFailureClass::Transient];

        return [
            [
                'key' => 'build_disk_full',
                'classes' => $build,
                'hints' => [BuildHintCode::DiskFull->value],
                'patterns' => [],
                'extract' => '/freed ([0-9.]+ ?[KMGT]B)/',
                'explain' => static function (FailureMatch $m, FailureContext $c): FailureExplanationData {
                    $freed = $m->group(1);

                    return new FailureExplanationData(
                        key: 'build_disk_full',
                        title: "{$c->serverName()} ran out of disk during the build",
                        cause: 'There was no space left for the build’s files and image layers.'.($freed === '' ? '' : " launchroom then removed old build cache and unused images and freed {$freed}."),
                        fix: $freed === ''
                            ? 'Free space on the server, then deploy again. If it keeps filling up, the server needs a bigger disk.'
                            : 'Deploy again. If it keeps filling up, the server needs a bigger disk.',
                        action: $c->serverId === null ? null : FailureFixAction::FreeDisk,
                        params: $c->serverId === null ? [] : ['server' => $c->serverId, 'serverName' => $c->serverName()],
                    );
                },
            ],
            [
                'key' => 'build_dns',
                'classes' => $build,
                'hints' => [BuildHintCode::BuildDns->value],
                'patterns' => [],
                'extract' => '/(?:getaddrinfo (?:EAI_AGAIN|ENOTFOUND) |Could not resolve host:? \'?|lookup |bad address \')'.self::HOST.'/i',
                'explain' => static function (FailureMatch $m, FailureContext $c): FailureExplanationData {
                    $host = $m->firstGroup();

                    return new FailureExplanationData(
                        key: 'build_dns',
                        title: $host === '' ? 'The build couldn’t look up a host name' : "The build couldn't look up {$host}",
                        cause: 'The build needed to download something, but '.$c->serverName(false).' couldn’t resolve '.($host === '' ? 'the name' : $host).'. Builds use the server’s name resolution.',
                        fix: 'Check the server’s DNS settings (/etc/resolv.conf) and that it can reach the internet, then deploy again. If the name is in your code or lockfile, check it for a typo.',
                    );
                },
            ],
            [
                'key' => 'build_database',
                'classes' => $build,
                'hints' => [BuildHintCode::BuildDatabase->value],
                'patterns' => [],
                'extract' => '/Error occurred prerendering page "([^"]{1,200})"/',
                'explain' => static function (FailureMatch $m): FailureExplanationData {
                    $page = $m->firstGroup();

                    return new FailureExplanationData(
                        key: 'build_database',
                        title: 'The build tried to reach your database',
                        cause: 'The framework renders '.($page === '' ? 'a page' : "the page {$page}").' while it builds, and that page queries the database. Builds run apart from your services, so the database isn’t reachable then.',
                        fix: 'Render the page when it is requested instead (in Next.js: export const dynamic = \'force-dynamic\', or await connection()), or skip the query when the database isn’t there.',
                    );
                },
            ],
            [
                'key' => 'build_idle',
                'classes' => $build,
                'hints' => [BuildHintCode::BuildIdle->value],
                'patterns' => [],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'build_idle',
                    title: 'The build printed nothing for 20 minutes',
                    cause: 'launchroom stopped a build step that showed no progress. Usually it waits for input, or for a download that never answers.',
                    fix: 'Check the last lines of the build log. A command that asks a question needs a flag such as --yes or CI=true.',
                ),
            ],
            [
                'key' => 'build_module_not_found',
                'classes' => $build,
                'hints' => [BuildHintCode::MissingModule->value, BuildHintCode::PythonModuleNotFound->value],
                'patterns' => [],
                'extract' => "/Cannot find module '([^']+)'|No module named '([^']+)'/",
                'explain' => static function (FailureMatch $m, FailureContext $c): FailureExplanationData {
                    $module = $m->firstGroup();
                    $python = $c->hasHint(BuildHintCode::PythonModuleNotFound->value);

                    return new FailureExplanationData(
                        key: 'build_module_not_found',
                        title: $module === '' ? 'A module is missing' : "The module {$module} is missing",
                        cause: 'The build imports it, but it isn’t installed.',
                        fix: $python
                            ? 'Add it to requirements.txt or pyproject.toml, then push.'
                            : 'Add it to dependencies in package.json and commit the lockfile, then push.',
                    );
                },
            ],
            [
                'key' => 'build_rate_limited',
                'classes' => $build,
                'hints' => [BuildHintCode::RegistryRateLimit->value],
                'patterns' => [],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'build_rate_limited',
                    title: 'A download limit was reached during the build',
                    cause: 'A registry or GitHub refused more downloads from this server for now. launchroom retried once.',
                    fix: 'Deploy again later. If it keeps happening, add a GITHUB_TOKEN variable that is available at build time.',
                    action: FailureFixAction::AddVariable,
                    params: ['name' => 'GITHUB_TOKEN', 'buildTime' => '1', 'type' => 'secret'],
                ),
            ],
            [
                'key' => 'django_settings_build',
                'classes' => $build,
                'hints' => [BuildHintCode::DjangoImproperlyConfigured->value],
                'patterns' => [],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'django_settings_build',
                    title: 'Django settings failed during the build',
                    cause: 'collectstatic loads the settings while the image is built, and they need a variable the build doesn’t have.',
                    fix: 'Make the settings import without runtime-only secrets, or set that variable’s availability to Build and runtime.',
                ),
            ],
        ];
    }
}
