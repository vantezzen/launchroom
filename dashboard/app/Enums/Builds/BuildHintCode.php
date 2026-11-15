<?php

declare(strict_types=1);

namespace App\Enums\Builds;

/**
 * A known build failure launchroomd recognised (TaskFailure.hints[].code, Go
 * `state.HintCode`; vocabulary.json `hintCodes`). The FailureCatalogue
 * explains them.
 */
enum BuildHintCode: string
{
    case RailpackCannotPlan = 'railpack-cannot-plan';
    case BuildkitNotReady = 'buildkit-not-ready';
    case BuildkitCrashLoop = 'buildkit-crash-loop';
    case BuildkitOom = 'buildkit-oom';
    case BuildkitUsernsRestricted = 'buildkit-userns-restricted';
    case PnpmOutdatedLockfile = 'pnpm-outdated-lockfile';
    case NpmCiLockfile = 'npm-ci-lockfile';
    case YarnFrozenLockfile = 'yarn-frozen-lockfile';
    case NodeGypToolchain = 'node-gyp-toolchain';
    case MissingModule = 'missing-module';
    case PhpExtension = 'php-extension';
    case DjangoImproperlyConfigured = 'django-improperly-configured';
    case PythonModuleNotFound = 'python-module-not-found';
    case OutOfMemory = 'out-of-memory';
    case NoStartCommand = 'no-start-command';
    case RegistryRateLimit = 'registry-rate-limit';
    case ViteEnvUndefined = 'vite-env-undefined';
}
