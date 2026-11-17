<?php

declare(strict_types=1);

namespace App\Support\Deployments\Failures;

use App\Data\Deployments\FailureExplanationData;
use App\Enums\Builds\BuildHintCode;
use App\Enums\DeploymentFailureClass;
use App\Enums\FailureFixAction;
use App\Enums\PortHint;
use App\Enums\VariableType;
use Closure;

/**
 * Known deployment failures explained in plain words (docs/04 §6,
 * direction.md §9 "explained failure"). Table-driven: the first entry whose
 * failure class and known-error code matches wins. Codes come from
 * launchroomd (BuildHintCode; PortHint, report.json WorkloadStatus.portHint),
 * never from its wording. Only
 * failures launchroomd has no code for (the app's own output: a missing
 * variable, a refused connection, running out of memory) match a pattern on
 * the failure message / log excerpt. Unknown failures return null and the
 * page falls back to the generic DeploymentFailureData hint.
 *
 * Entries for containers that didn't start or stay up live in
 * RuntimeFailures, newer build hints in BuildFailures. An entry matches by
 * hint code, by a typed fact (`when`) or by a pattern.
 *
 * @phpstan-type Entry array{key: string, classes: list<DeploymentFailureClass>|null, hints: list<string>, patterns: list<string>, extract?: string, when?: Closure(FailureContext): bool, explain: Closure(FailureMatch, FailureContext): ?FailureExplanationData}
 */
final class FailureCatalogue
{
    private const string VARIABLE = '([A-Z][A-Z0-9_]{1,127})';

    public static function explain(FailureContext $context): ?FailureExplanationData
    {
        $text = $context->text();

        foreach (self::entries() as $entry) {
            if ($entry['classes'] !== null && ! in_array($context->class, $entry['classes'], true)) {
                continue;
            }

            $matches = new FailureMatch;
            $hit = array_intersect($entry['hints'], $context->hintCodes) !== []
                || (isset($entry['when']) && ($entry['when'])($context));

            if ($hit && isset($entry['extract']) && preg_match($entry['extract'], $text, $found) === 1) {
                $matches = new FailureMatch($found);
            }

            foreach ($entry['patterns'] as $pattern) {
                if (preg_match($pattern, $text, $found) === 1) {
                    $matches = new FailureMatch($found);
                    $hit = true;

                    break;
                }
            }

            if ($hit || ($entry['patterns'] === [] && $entry['hints'] === [] && ! isset($entry['when']))) {
                $explanation = ($entry['explain'])($matches, $context);

                if ($explanation instanceof FailureExplanationData) {
                    return $explanation;
                }
            }
        }

        return null;
    }

    /**
     * Keys of every entry, in match order (for docs and tests).
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::entries(), 'key');
    }

    /**
     * @return list<Entry>
     */
    private static function entries(): array
    {
        $build = [DeploymentFailureClass::BuildError, DeploymentFailureClass::PlanError];
        $running = [DeploymentFailureClass::HealthTimeout, DeploymentFailureClass::RuntimeError, DeploymentFailureClass::PortMismatch];

        return [
            ...RuntimeFailures::typed(),
            [
                'key' => 'cannot_plan',
                'classes' => [DeploymentFailureClass::PlanError],
                'hints' => [BuildHintCode::RailpackCannotPlan->value],
                'patterns' => [],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'cannot_plan',
                    title: "launchroom couldn't detect how to build this app",
                    cause: 'Nothing in the repository told Railpack which language or framework this is.',
                    fix: 'Generate a Dockerfile that launchroom builds and starts before you use it, or set the build and start commands yourself.',
                    action: FailureFixAction::GenerateDockerfile,
                    params: ['trigger' => 'plan_failed'],
                ),
            ],
            [
                'key' => 'buildkit_userns',
                'classes' => [DeploymentFailureClass::Transient],
                'hints' => [BuildHintCode::BuildkitUsernsRestricted->value],
                'patterns' => [],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'buildkit_userns',
                    title: 'This server blocks what builds need',
                    cause: 'Ubuntu 23.10 and later restrict the user namespaces that launchroom’s rootless BuildKit uses, and this server doesn’t have launchroom’s AppArmor profile that allows them for the build container, so BuildKit stops right after it starts.',
                    fix: 'Run the launchroom installer on the server again and answer yes when it asks about builds. It loads the AppArmor profile /etc/apparmor.d/launchroom-buildkit, which allows user namespaces for launchroom’s build container only; the restriction stays on for everything else. launchroom switches to the profile within a few minutes. Then redeploy. The exact commands to run by hand are in the build log and in the docs under Troubleshooting → Builds.',
                ),
            ],
            [
                'key' => 'buildkit_oom',
                'classes' => [DeploymentFailureClass::Transient],
                'hints' => [BuildHintCode::BuildkitOom->value],
                'patterns' => [],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'buildkit_oom',
                    title: 'The build server ran out of memory',
                    cause: 'BuildKit was stopped because the server had no memory left. Small servers that also run launchroom itself get tight while they build.',
                    fix: 'Add swap or memory to the server, or add a bigger build server, then redeploy.',
                ),
            ],
            [
                'key' => 'buildkit_unavailable',
                'classes' => [DeploymentFailureClass::Transient],
                'hints' => [BuildHintCode::BuildkitCrashLoop->value, BuildHintCode::BuildkitNotReady->value],
                'patterns' => [],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'buildkit_unavailable',
                    title: 'BuildKit did not start on the build server',
                    cause: 'BuildKit, which builds your image on the server, kept restarting or did not answer in time. launchroom waited and retried once.',
                    fix: 'Redeploy to try again. If it keeps failing, check BuildKit’s last lines in the build log. Low memory or disk space on the server is the usual cause.',
                ),
            ],
            [
                'key' => 'no_start_command',
                'classes' => $build,
                'hints' => [BuildHintCode::NoStartCommand->value],
                'patterns' => [],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'no_start_command',
                    title: 'Nothing says how to start this app',
                    cause: 'The build found no start script, Procfile or start command.',
                    fix: 'Add a start command, or generate a Dockerfile that launchroom verifies by starting the app.',
                    action: FailureFixAction::GenerateDockerfile,
                    params: ['trigger' => 'build_failed'],
                ),
            ],
            [
                'key' => 'port_mismatch',
                'classes' => $running,
                'hints' => [PortHint::WrongPort->value],
                'patterns' => [],
                'explain' => static function (FailureMatch $m, FailureContext $c): ?FailureExplanationData {
                    $detected = $c->listening[0] ?? null;
                    $configured = $c->hintPort ?? $c->currentPort;

                    if ($detected === null || $configured === null) {
                        return null;
                    }

                    return new FailureExplanationData(
                        key: 'port_mismatch',
                        title: "Your app listens on port {$detected}, not {$configured}",
                        cause: "The server checks port {$configured}, but the app opened port {$detected}, so the health check never got an answer.",
                        fix: "Use port {$detected} for this service, or start the app on port {$configured} (most frameworks read the PORT variable).",
                        action: FailureFixAction::SetPort,
                        params: array_filter(['port' => $detected, 'currentPort' => $configured]),
                    );
                },
            ],
            [
                'key' => 'port_loopback',
                'classes' => $running,
                'hints' => [PortHint::LoopbackOnly->value],
                'patterns' => [],
                'explain' => static fn (FailureMatch $m, FailureContext $c): FailureExplanationData => new FailureExplanationData(
                    key: 'port_loopback',
                    title: 'Your app only accepts connections from itself',
                    cause: PortHint::LoopbackOnly->fact($c->hintPort ?? $c->currentPort, []),
                    fix: 'Bind to 0.0.0.0 instead, for example with --host 0.0.0.0 or a HOST variable.',
                    action: FailureFixAction::AddVariable,
                    params: ['name' => 'HOST', 'value' => '0.0.0.0'],
                ),
            ],
            [
                'key' => 'no_port',
                'classes' => $running,
                'hints' => [PortHint::NoPort->value],
                'patterns' => [],
                'explain' => static fn (FailureMatch $m, FailureContext $c): FailureExplanationData => new FailureExplanationData(
                    key: 'no_port',
                    title: 'Your app is not listening on any port',
                    cause: PortHint::NoPort->fact($c->hintPort ?? $c->currentPort, []),
                    fix: 'Check the start command starts the web server. If this is a background process, turn it into a worker without a port.',
                ),
            ],
            [
                'key' => 'out_of_memory_build',
                'classes' => $build,
                'hints' => [BuildHintCode::OutOfMemory->value],
                'patterns' => [],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'out_of_memory_build',
                    title: 'The build ran out of memory',
                    cause: 'The build process used more memory than the build server allows and was stopped.',
                    fix: 'Use a build server with more memory, or limit the build, for example with NODE_OPTIONS=--max-old-space-size=2048.',
                    action: FailureFixAction::AddVariable,
                    params: ['name' => 'NODE_OPTIONS', 'value' => '--max-old-space-size=2048', 'buildTime' => '1'],
                ),
            ],
            ...RuntimeFailures::output(),
            [
                'key' => 'pnpm_lockfile',
                'classes' => $build,
                'hints' => [BuildHintCode::PnpmOutdatedLockfile->value],
                'patterns' => [],
                'explain' => static fn (): FailureExplanationData => self::lockfile('pnpm_lockfile', 'pnpm-lock.yaml', 'pnpm install'),
            ],
            [
                'key' => 'npm_lockfile',
                'classes' => $build,
                'hints' => [BuildHintCode::NpmCiLockfile->value],
                'patterns' => [],
                'explain' => static fn (): FailureExplanationData => self::lockfile('npm_lockfile', 'package-lock.json', 'npm install'),
            ],
            [
                'key' => 'yarn_lockfile',
                'classes' => $build,
                'hints' => [BuildHintCode::YarnFrozenLockfile->value],
                'patterns' => [],
                'explain' => static fn (): FailureExplanationData => self::lockfile('yarn_lockfile', 'yarn.lock', 'yarn install'),
            ],
            [
                'key' => 'php_extension',
                'classes' => $build,
                'hints' => [BuildHintCode::PhpExtension->value],
                'patterns' => [],
                // The code says an extension is missing; its name is only in the log.
                'extract' => '/requires ext-([a-z0-9_]+)|PHP extension ([a-z0-9_]+) is missing/i',
                'explain' => static function (FailureMatch $m): FailureExplanationData {
                    $extension = strtolower($m->firstGroup());
                    $named = $extension === '' ? 'A PHP extension' : "The PHP extension {$extension}";

                    return new FailureExplanationData(
                        key: 'php_extension',
                        title: $extension === '' ? 'A PHP extension is missing' : "The PHP extension {$extension} is missing",
                        cause: "{$named} is required by a Composer package but is not installed in the build image.",
                        fix: 'Add it to composer.json as "ext-'.($extension === '' ? 'name' : $extension).'": "*", or list it in RAILPACK_PHP_EXTENSIONS.',
                        action: FailureFixAction::AddVariable,
                        params: array_filter(['name' => 'RAILPACK_PHP_EXTENSIONS', 'value' => $extension, 'buildTime' => '1']),
                    );
                },
            ],
            [
                'key' => 'native_module',
                'classes' => $build,
                'hints' => [BuildHintCode::NodeGypToolchain->value],
                'patterns' => [],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'native_module',
                    title: 'A native module could not be compiled',
                    cause: 'A dependency builds native code with node-gyp, and the build image has no compiler toolchain.',
                    fix: 'Add the build tools with RAILPACK_BUILD_APT_PACKAGES="python3 make g++", or switch to a prebuilt alternative.',
                    action: FailureFixAction::AddVariable,
                    params: ['name' => 'RAILPACK_BUILD_APT_PACKAGES', 'value' => 'python3 make g++', 'buildTime' => '1'],
                ),
            ],
            ...BuildFailures::entries(),
            [
                'key' => 'missing_variable',
                'classes' => null,
                'hints' => [],
                'patterns' => [
                    '/\b'.self::VARIABLE.' environment variable is not set/',
                    '/import\.meta\.env\.'.self::VARIABLE.' is undefined/',
                    '/missing (?:required )?(?:environment|env) variable[:\s]+[\'"`]?'.self::VARIABLE.'/i',
                    '/(?:environment|env) variable [\'"`]?'.self::VARIABLE.'[\'"`]? (?:is )?(?:not set|missing|required|undefined)/i',
                    '/KeyError: [\'"]'.self::VARIABLE.'[\'"]/',
                    '/\b'.self::VARIABLE.' (?:is not defined|is not set|is required|must be set)/',
                ],
                'explain' => static function (FailureMatch $m, FailureContext $context): ?FailureExplanationData {
                    $name = $m->firstGroup();

                    // "X is not defined" with a short or camel-cased name is a code bug, not a variable.
                    if ($name === '' || (! str_contains($name, '_') && strlen($name) < 4)) {
                        return null;
                    }

                    $vite = str_contains($m->group(0), 'import.meta.env') || in_array($context->class, [DeploymentFailureClass::BuildError, DeploymentFailureClass::PlanError], true);

                    return new FailureExplanationData(
                        key: 'missing_variable',
                        title: "The variable {$name} is missing",
                        cause: $vite
                            ? "The app reads {$name} while it is built, but the build did not get it."
                            : "The app reads {$name}, but it is not set for this environment.",
                        fix: $vite
                            ? "Open {$name} in this service’s variables, set its value and availability to Build and runtime, then deploy again."
                            : "Add {$name} to this service's variables, then deploy again.",
                        action: FailureFixAction::AddVariable,
                        params: array_filter(['name' => $name, 'buildTime' => $vite ? '1' : '', 'type' => VariableType::suggestFor($name) === VariableType::Secret ? 'secret' : null]),
                    );
                },
            ],
            ...RuntimeFailures::probe(),
            [
                'key' => 'health_refused',
                'classes' => [DeploymentFailureClass::HealthTimeout],
                'hints' => [],
                'patterns' => ['/connection refused/i'],
                'explain' => static fn (FailureMatch $m, FailureContext $c): FailureExplanationData => new FailureExplanationData(
                    key: 'health_refused',
                    title: $c->currentPort === null ? 'Nothing answered the health check' : "Nothing answered on port {$c->currentPort}",
                    cause: 'The app was running, but no process accepted connections on the port the server checks.',
                    fix: 'Make sure the app listens on this port on 0.0.0.0, or change the port in the service settings.',
                    action: FailureFixAction::SetPort,
                    params: array_filter(['currentPort' => $c->currentPort]),
                ),
            ],
            [
                'key' => 'health_timeout',
                'classes' => [DeploymentFailureClass::HealthTimeout],
                'hints' => [],
                'patterns' => [],
                'explain' => static function (FailureMatch $m, FailureContext $c): FailureExplanationData {
                    $current = $c->healthStartPeriodSeconds;

                    return new FailureExplanationData(
                        key: 'health_timeout',
                        title: 'Your app took too long to become healthy',
                        cause: 'The health check did not pass before the deadline. Apps that run migrations or warm caches on start often need longer.',
                        fix: 'Give it more time to start, or check that the health check path answers with a 2xx or 3xx status.',
                        action: FailureFixAction::RaiseHealthTimeout,
                        params: $current === null ? [] : ['currentSeconds' => $current, 'suggestedSeconds' => max(60, $current * 2)],
                    );
                },
            ],
        ];
    }

    private static function lockfile(string $key, string $file, string $command): FailureExplanationData
    {
        return new FailureExplanationData(
            key: $key,
            title: "{$file} is out of date",
            cause: 'The lockfile does not match package.json, and builds install exactly what the lockfile says.',
            fix: "Run {$command} locally, commit {$file} and push.",
        );
    }
}
