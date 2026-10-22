<?php

declare(strict_types=1);

use App\Enums\DeploymentFailureClass as F;
use App\Enums\FailureFixAction;
use App\Support\Deployments\Failures\FailureCatalogue;
use App\Support\Deployments\Failures\FailureContext;

dataset('failures', [
    'railpack could not plan' => [new FailureContext(F::PlanError, 'Build failed (plan): railpack could not plan this app (see the plan log)'), 'cannot_plan', FailureFixAction::GenerateDockerfile, ['trigger' => 'plan_failed']],
    'buildkit userns (hint)' => [new FailureContext(F::Transient, 'Build failed (setup): buildkitd keeps restarting', hintCodes: ['buildkit-userns-restricted', 'buildkit-crash-loop']), 'buildkit_userns', null, []],
    'buildkit oom (hint)' => [new FailureContext(F::Transient, 'Build failed (setup): buildkitd keeps restarting (restarted 6 times, last exit code 137): it was killed for running out of memory', hintCodes: ['buildkit-oom', 'buildkit-crash-loop']), 'buildkit_oom', null, []],
    'buildkit crash loop (hint)' => [new FailureContext(F::Transient, 'Build failed (setup): buildkitd keeps restarting', hintCodes: ['buildkit-crash-loop']), 'buildkit_unavailable', null, []],
    'buildkit not ready (older servers)' => [new FailureContext(F::Transient, 'Build failed (setup): internal_error (setup): buildkitd: not ready: failed to list workers: Unavailable'), 'buildkit_unavailable', null, []],
    'no start command (hint)' => [new FailureContext(F::BuildError, 'Build failed (build): exit code: 1', hintCodes: ['no-start-command']), 'no_start_command', FailureFixAction::GenerateDockerfile, ['trigger' => 'build_failed']],
    'port mismatch' => [new FailureContext(F::HealthTimeout, 'The new version did not become healthy in time: connection refused; the app listens on 5173, not 3000', currentPort: 3000), 'port_mismatch', FailureFixAction::SetPort, ['port' => 5173, 'currentPort' => 3000]],
    'port mismatch, several ports' => [new FailureContext(F::RuntimeError, 'the app listens on 8080, 9000, not 80'), 'port_mismatch', FailureFixAction::SetPort, ['port' => 8080, 'currentPort' => 80]],
    'loopback only' => [new FailureContext(F::HealthTimeout, 'did not become healthy in time: the app listens on 127.0.0.1:3000 only; bind to 0.0.0.0 so it is reachable'), 'port_loopback', FailureFixAction::AddVariable, ['name' => 'HOST', 'value' => '0.0.0.0']],
    'no port' => [new FailureContext(F::HealthTimeout, 'the app does not listen on any TCP port (expected 3000)'), 'no_port', null, []],
    'build out of memory (hint)' => [new FailureContext(F::BuildError, 'Build failed (build): exit code: 1', hintCodes: ['out-of-memory']), 'out_of_memory_build', FailureFixAction::AddVariable, ['name' => 'NODE_OPTIONS', 'value' => '--max-old-space-size=2048', 'buildTime' => '1']],
    'build out of memory (log)' => [new FailureContext(F::BuildError, 'Build failed', logExcerpt: 'FATAL ERROR: Reached heap limit Allocation failed - JavaScript heap out of memory'), 'out_of_memory_build', FailureFixAction::AddVariable, ['name' => 'NODE_OPTIONS', 'value' => '--max-old-space-size=2048', 'buildTime' => '1']],
    'runtime out of memory' => [new FailureContext(F::RuntimeError, 'exited: killed: out of memory (raise the memory limit)'), 'out_of_memory', null, []],
    'exit 137' => [new FailureContext(F::RuntimeError, 'The app exited with code 137'), 'out_of_memory', null, []],
    'pnpm lockfile' => [new FailureContext(F::BuildError, 'Build failed (install): ERR_PNPM_OUTDATED_LOCKFILE  Cannot install with "frozen-lockfile"'), 'pnpm_lockfile', null, []],
    'npm lockfile (hint)' => [new FailureContext(F::BuildError, 'Build failed (install): exit code: 1', hintCodes: ['npm-ci-lockfile']), 'npm_lockfile', null, []],
    'yarn lockfile' => [new FailureContext(F::BuildError, 'YN0028: The lockfile would have been modified by this install, which is explicitly forbidden.'), 'yarn_lockfile', null, []],
    'php extension' => [new FailureContext(F::BuildError, 'laravel/horizon v5.0 requires ext-pcntl * -> it is missing from your system.'), 'php_extension', FailureFixAction::AddVariable, ['name' => 'RAILPACK_PHP_EXTENSIONS', 'value' => 'pcntl', 'buildTime' => '1']],
    'node-gyp' => [new FailureContext(F::BuildError, 'Build failed', logExcerpt: "gyp ERR! stack Error: not found: make\ngyp ERR! not ok"), 'native_module', FailureFixAction::AddVariable, ['name' => 'RAILPACK_BUILD_APT_PACKAGES', 'value' => 'python3 make g++', 'buildTime' => '1']],
    'missing variable (runtime)' => [new FailureContext(F::RuntimeError, 'Error: DATABASE_URL is not defined'), 'missing_variable', FailureFixAction::AddVariable, ['name' => 'DATABASE_URL']],
    'missing variable (message)' => [new FailureContext(F::ReleaseError, 'Missing required environment variable: STRIPE_KEY'), 'missing_variable', FailureFixAction::AddVariable, ['name' => 'STRIPE_KEY', 'type' => 'secret']],
    'missing variable (python)' => [new FailureContext(F::RuntimeError, "KeyError: 'SECRET_KEY'"), 'missing_variable', FailureFixAction::AddVariable, ['name' => 'SECRET_KEY', 'type' => 'secret']],
    'missing variable (vite build)' => [new FailureContext(F::BuildError, 'Build failed', logExcerpt: 'import.meta.env.VITE_API_URL is undefined'), 'missing_variable', FailureFixAction::AddVariable, ['name' => 'VITE_API_URL', 'buildTime' => '1']],
    'missing auth secret (next build)' => [new FailureContext(F::BuildError, 'failed to solve: exit code: 1', logExcerpt: '#17 Error: AUTH_SECRET environment variable is not set. It is required for encryption/decryption.'), 'missing_variable', FailureFixAction::AddVariable, ['name' => 'AUTH_SECRET', 'buildTime' => '1', 'type' => 'secret']],
    'health refused' => [new FailureContext(F::HealthTimeout, 'The new version did not become healthy in time: connection refused', currentPort: 8000), 'health_refused', FailureFixAction::SetPort, ['currentPort' => 8000]],
    'health timeout' => [new FailureContext(F::HealthTimeout, 'The new version did not become healthy in time: HTTP 503', healthStartPeriodSeconds: 30), 'health_timeout', FailureFixAction::RaiseHealthTimeout, ['currentSeconds' => 30, 'suggestedSeconds' => 60]],
]);

test('known failures are explained', function (FailureContext $context, string $key, ?FailureFixAction $action, array $params): void {
    $explanation = FailureCatalogue::explain($context);

    expect($explanation)->not->toBeNull()
        ->and($explanation?->key)->toBe($key)
        ->and($explanation?->action)->toBe($action)
        ->and($explanation?->params)->toBe($params)
        ->and($explanation?->title)->not->toBeEmpty()
        ->and($explanation?->cause)->not->toBeEmpty()
        ->and($explanation?->fix)->not->toBeEmpty();
})->with('failures');

test('unknown failures and code errors are not explained', function (FailureContext $context): void {
    expect(FailureCatalogue::explain($context))->toBeNull();
})->with([
    'unknown build error' => [new FailureContext(F::BuildError, 'Build failed (build): exit code: 2')],
    'placement' => [new FailureContext(F::PlacementError, 'No server with the runtime role has 2048 MB free.')],
    'javascript reference error' => [new FailureContext(F::RuntimeError, 'ReferenceError: foo is not defined')],
    'class does not apply' => [new FailureContext(F::PlanError, 'the app listens on 5173, not 3000')],
]);

test('every catalogue entry is covered and copy never says agent or daemon', function (): void {
    expect(FailureCatalogue::keys())->toBe([
        'cannot_plan', 'buildkit_userns', 'buildkit_oom', 'buildkit_unavailable', 'no_start_command', 'port_mismatch', 'port_loopback', 'no_port', 'out_of_memory_build', 'out_of_memory', 'pnpm_lockfile', 'npm_lockfile',
        'yarn_lockfile', 'php_extension', 'native_module', 'missing_variable', 'health_refused', 'health_timeout',
    ]);

    $explanation = FailureCatalogue::explain(new FailureContext(F::HealthTimeout, 'x'));
    $copy = strtolower(($explanation?->title ?? '').($explanation?->cause ?? '').($explanation?->fix ?? ''));
    expect($copy)->not->toBe('')->not->toContain('agent')->not->toContain('daemon');
});
