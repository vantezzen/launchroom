<?php

declare(strict_types=1);

use App\Enums\Builds\BuildHintCode;
use App\Enums\DeploymentFailureClass as F;
use App\Enums\FailureFixAction;
use App\Enums\ProbeHint;
use App\Enums\WorkloadFailureCode;
use App\Support\Deployments\Failures\FailureCatalogue;
use App\Support\Deployments\Failures\FailureContext;

dataset('failures', [
    'railpack could not plan' => [new FailureContext(F::PlanError, 'Build failed (plan): railpack could not plan this app (see the plan log)', hintCodes: ['railpack-cannot-plan']), 'cannot_plan', FailureFixAction::GenerateDockerfile, ['trigger' => 'plan_failed']],
    'buildkit userns (hint)' => [new FailureContext(F::Transient, 'Build failed (setup): buildkitd keeps restarting', hintCodes: ['buildkit-userns-restricted', 'buildkit-crash-loop']), 'buildkit_userns', null, []],
    'buildkit oom (hint)' => [new FailureContext(F::Transient, 'Build failed (setup): buildkitd keeps restarting (restarted 6 times, last exit code 137): it was killed for running out of memory', hintCodes: ['buildkit-oom', 'buildkit-crash-loop']), 'buildkit_oom', null, []],
    'buildkit crash loop (hint)' => [new FailureContext(F::Transient, 'Build failed (setup): buildkitd keeps restarting', hintCodes: ['buildkit-crash-loop']), 'buildkit_unavailable', null, []],
    'buildkit not ready' => [new FailureContext(F::Transient, 'Build failed (setup): internal_error (setup): buildkitd: not ready: failed to list workers: Unavailable', hintCodes: ['buildkit-not-ready']), 'buildkit_unavailable', null, []],
    'no start command (hint)' => [new FailureContext(F::BuildError, 'Build failed (build): exit code: 1', hintCodes: ['no-start-command']), 'no_start_command', FailureFixAction::GenerateDockerfile, ['trigger' => 'build_failed']],
    'port mismatch' => [new FailureContext(F::HealthTimeout, 'The new version did not become healthy in time: connection refused.', hintCodes: ['wrong_port'], currentPort: 3000, listening: [5173]), 'port_mismatch', FailureFixAction::SetPort, ['port' => 5173, 'currentPort' => 3000]],
    'port mismatch, several ports' => [new FailureContext(F::PortMismatch, 'The app listens on 8080, 9000, not 80.', hintCodes: ['wrong_port'], listening: [8080, 9000], hintPort: 80), 'port_mismatch', FailureFixAction::SetPort, ['port' => 8080, 'currentPort' => 80]],
    'loopback only' => [new FailureContext(F::HealthTimeout, 'did not become healthy in time.', hintCodes: ['loopback_only'], hintPort: 3000), 'port_loopback', FailureFixAction::AddVariable, ['name' => 'HOST', 'value' => '0.0.0.0']],
    'no port' => [new FailureContext(F::HealthTimeout, 'did not become healthy in time.', hintCodes: ['no_port'], hintPort: 3000), 'no_port', null, []],
    'build out of memory (hint)' => [new FailureContext(F::BuildError, 'Build failed (build): exit code: 1', hintCodes: ['out-of-memory']), 'out_of_memory_build', FailureFixAction::AddVariable, ['name' => 'NODE_OPTIONS', 'value' => '--max-old-space-size=2048', 'buildTime' => '1']],
    'runtime out of memory' => [new FailureContext(F::RuntimeError, 'exited: killed: out of memory (raise the memory limit)'), 'out_of_memory', null, []],
    'exit 137 with the OOM flag' => [new FailureContext(F::RuntimeError, 'The container keeps crashing: it exited with code 137', exitCode: 137, oomKilled: true), 'out_of_memory', null, []],
    'exit 137 without the OOM flag' => [new FailureContext(F::RuntimeError, 'The container exited with code 137 before it became healthy.', exitCode: 137), 'killed', null, []],
    'segfault' => [new FailureContext(F::RuntimeError, 'The container keeps crashing: it exited with code 139', exitCode: 139), 'crashed_signal', null, []],
    // Typed start and pull failures (launchroomd WorkloadFailureCode, G5).
    'command not found (code)' => [new FailureContext(F::RuntimeError, "server-2 couldn't run the container: exec: \"bundle\": executable file not found", hintCodes: ['command_not_found'], server: 'server-2'), 'command_not_found', null, []],
    'command not found (exit 127 only)' => [new FailureContext(F::RuntimeError, 'The container exited with code 127 before it became healthy.', exitCode: 127), 'command_not_found', null, []],
    'not executable' => [new FailureContext(F::RuntimeError, 'x', hintCodes: ['not_executable']), 'not_executable', null, []],
    'wrong platform' => [new FailureContext(F::RuntimeError, "server-2 couldn't run the container: the image is built for linux/amd64; this server runs linux/arm64", hintCodes: ['wrong_platform']), 'wrong_platform', null, []],
    'mount failed' => [new FailureContext(F::RuntimeError, 'x', hintCodes: ['mount_failed']), 'mount_failed', null, []],
    'invalid user' => [new FailureContext(F::RuntimeError, 'x', hintCodes: ['invalid_user']), 'invalid_user', null, []],
    'image not found' => [new FailureContext(F::Transient, 'pull failed: manifest unknown', hintCodes: ['image_not_found']), 'image_not_found', null, []],
    'unauthorized' => [new FailureContext(F::Transient, 'pull failed: denied', hintCodes: ['unauthorized']), 'image_unauthorized', null, []],
    'rate limited' => [new FailureContext(F::Transient, 'pull failed: toomanyrequests', hintCodes: ['rate_limited']), 'image_rate_limited', null, []],
    'registry unreachable' => [new FailureContext(F::Transient, 'pull failed: i/o timeout', hintCodes: ['network']), 'image_network', null, []],
    'no space (runtime, server known)' => [new FailureContext(F::Transient, 'pull failed: no space left on device', hintCodes: ['no_space'], server: 'server-2', serverId: 'srv_01jc0000000000000000000000'), 'no_space', FailureFixAction::FreeDisk, ['server' => 'srv_01jc0000000000000000000000', 'serverName' => 'server-2']],
    'host not allowed (probe hint)' => [new FailureContext(F::HealthTimeout, 'kept failing (HTTP 400 (expected 2xx-4xx): the app doesn\'t accept this domain as its host, 4 attempts)', hintCodes: ['host_not_allowed'], probeBody: 'DisallowedHost at / Invalid HTTP_HOST header'), 'host_not_allowed', null, []],
    // The app's own output (G11).
    'volume permission (numeric user)' => [new FailureContext(F::RuntimeError, "Error: EACCES: permission denied, open '/data/db.sqlite'", imageUser: '1000'), 'volume_permission', FailureFixAction::FixVolumeOwner, ['uid' => 1000]],
    'volume permission (named user)' => [new FailureContext(F::RuntimeError, "PermissionError: [Errno 13] Permission denied: '/app/media/x'", imageUser: 'app'), 'volume_permission', null, []],
    'address in use' => [new FailureContext(F::RuntimeError, 'Error: listen EADDRINUSE: address already in use :::3000'), 'port_in_use', null, []],
    'database refused' => [new FailureContext(F::RuntimeError, 'Error: connect ECONNREFUSED 127.0.0.1:5432'), 'database_unreachable', null, []],
    'prisma unreachable' => [new FailureContext(F::HealthTimeout, "P1001: Can't reach database server at `db.internal`:5432"), 'database_unreachable', null, []],
    'module not found' => [new FailureContext(F::RuntimeError, "Error: Cannot find module 'express'"), 'module_not_found', null, []],
    'user switch' => [new FailureContext(F::RuntimeError, 'nginx: [emerg] setgid(101) failed (1: Operation not permitted)'), 'user_switch', FailureFixAction::AddCapabilities, []],
    'gosu' => [new FailureContext(F::RuntimeError, 'error: failed switching to "redis": operation not permitted', logExcerpt: 'gosu: setgroups: Operation not permitted'), 'user_switch', FailureFixAction::AddCapabilities, []],
    'rails master key' => [new FailureContext(F::ReleaseError, 'ActiveSupport::MessageEncryptor::InvalidMessage'), 'rails_master_key', FailureFixAction::AddVariable, ['name' => 'RAILS_MASTER_KEY', 'type' => 'secret']],
    'rails secret key base' => [new FailureContext(F::RuntimeError, 'Missing `secret_key_base` for \'production\' environment'), 'rails_master_key', FailureFixAction::AddVariable, ['name' => 'RAILS_MASTER_KEY', 'type' => 'secret']],
    'rails pid' => [new FailureContext(F::RuntimeError, 'A server is already running (pid: 1, file: /rails/tmp/pids/server.pid).'), 'rails_server_running', null, []],
    'django disallowed host' => [new FailureContext(F::HealthTimeout, 'x', logExcerpt: "Invalid HTTP_HOST header: 'example.com'. You may need to add 'example.com' to ALLOWED_HOSTS."), 'django_disallowed_host', null, []],
    'rails blocked host' => [new FailureContext(F::HealthTimeout, 'x', logExcerpt: 'Blocked hosts: example.com'), 'rails_blocked_host', null, []],
    'phoenix check origin' => [new FailureContext(F::RuntimeError, 'Could not check origin for Phoenix.Socket transport.'), 'phoenix_check_origin', null, []],
    'jvm oom' => [new FailureContext(F::RuntimeError, 'Exception in thread "main" java.lang.OutOfMemoryError: Java heap space'), 'jvm_out_of_memory', null, []],
    'gunicorn timeout' => [new FailureContext(F::HealthTimeout, 'x', logExcerpt: '[CRITICAL] WORKER TIMEOUT (pid:12)'), 'gunicorn_timeout', FailureFixAction::AddVariable, ['name' => 'GUNICORN_CMD_ARGS', 'value' => '--timeout 120']],
    'enospc at runtime' => [new FailureContext(F::RuntimeError, 'Error: ENOSPC: no space left on device, write'), 'disk_full_runtime', null, []],
    // Health checks that got an answer (G46).
    'health 404' => [new FailureContext(F::HealthTimeout, 'The container never became healthy within 60 seconds: GET / on :8000 kept failing (HTTP 404 (expected 2xx-3xx), 9 attempts).'), 'health_status', FailureFixAction::Accept4xx, []],
    'health 500' => [new FailureContext(F::HealthTimeout, 'kept failing (HTTP 500 (expected 2xx-3xx), 3 attempts)'), 'health_status', null, []],
    'health not http' => [new FailureContext(F::HealthTimeout, 'GET / on :6379 kept failing (EOF, 9 attempts).'), 'health_not_http', FailureFixAction::UseTcpHealthcheck, []],
    // Host-caused build failures (G17, G55).
    'build disk full (freed)' => [new FailureContext(F::Transient, 'Build failed', hintCodes: ['disk-full'], logExcerpt: 'This server ran out of disk space during the build. launchroom removed old images and build cache and freed 3.1 GB; retry the deploy.', server: 'build-1', serverId: 'srv_01jc0000000000000000000001'), 'build_disk_full', FailureFixAction::FreeDisk, ['server' => 'srv_01jc0000000000000000000001', 'serverName' => 'build-1']],
    'build dns' => [new FailureContext(F::BuildError, 'Build failed', hintCodes: ['build-dns'], logExcerpt: 'npm error getaddrinfo EAI_AGAIN registry.npmjs.org'), 'build_dns', null, []],
    'build database' => [new FailureContext(F::BuildError, 'Build failed', hintCodes: ['build-database'], logExcerpt: 'Error occurred prerendering page "/blog". Read more'), 'build_database', null, []],
    'build idle' => [new FailureContext(F::BuildTimeout, 'Build failed', hintCodes: ['build-idle']), 'build_idle', null, []],
    'build missing module' => [new FailureContext(F::BuildError, 'Build failed', hintCodes: ['missing-module'], logExcerpt: "Error: Cannot find module 'tailwindcss'"), 'build_module_not_found', null, []],
    'pnpm lockfile (hint)' => [new FailureContext(F::BuildError, 'Build failed (install): exit code: 1', hintCodes: ['pnpm-outdated-lockfile']), 'pnpm_lockfile', null, []],
    'npm lockfile (hint)' => [new FailureContext(F::BuildError, 'Build failed (install): exit code: 1', hintCodes: ['npm-ci-lockfile']), 'npm_lockfile', null, []],
    'yarn lockfile (hint)' => [new FailureContext(F::BuildError, 'Build failed (install): exit code: 1', hintCodes: ['yarn-frozen-lockfile']), 'yarn_lockfile', null, []],
    'php extension (hint, name from the log)' => [new FailureContext(F::BuildError, 'laravel/horizon v5.0 requires ext-pcntl * -> it is missing from your system.', hintCodes: ['php-extension']), 'php_extension', FailureFixAction::AddVariable, ['name' => 'RAILPACK_PHP_EXTENSIONS', 'value' => 'pcntl', 'buildTime' => '1']],
    'node-gyp (hint)' => [new FailureContext(F::BuildError, 'Build failed', hintCodes: ['node-gyp-toolchain']), 'native_module', FailureFixAction::AddVariable, ['name' => 'RAILPACK_BUILD_APT_PACKAGES', 'value' => 'python3 make g++', 'buildTime' => '1']],
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
    'class does not apply' => [new FailureContext(F::PlanError, 'x', hintCodes: ['wrong_port'], listening: [5173], hintPort: 3000)],
    'launchroomd wording without a code' => [new FailureContext(F::BuildError, 'Build failed (install): ERR_PNPM_OUTDATED_LOCKFILE; the app listens on 5173, not 3000')],
    'port mismatch without the ports' => [new FailureContext(F::PortMismatch, 'x', hintCodes: ['wrong_port'])],
    // 137 is SIGKILL: out of memory only with launchroomd's OOM flag (G6).
    '137 in words, no facts' => [new FailureContext(F::RuntimeError, 'The app exited with code 137')],
    'stopped by launchroom after its stop timeout' => [new FailureContext(F::RuntimeError, 'x', exitCode: 137, byLaunchroom: true)],
    'other start failure' => [new FailureContext(F::RuntimeError, 'x', hintCodes: ['other'])],
]);

test('every catalogue entry is covered and copy never says agent or daemon', function (): void {
    expect(FailureCatalogue::keys())->toBe([
        'command_not_found', 'not_executable', 'wrong_platform', 'mount_failed', 'invalid_user', 'image_not_found', 'image_unauthorized', 'image_rate_limited', 'image_network', 'no_space', 'host_not_allowed',
        'cannot_plan', 'buildkit_userns', 'buildkit_oom', 'buildkit_unavailable', 'no_start_command', 'port_mismatch', 'port_loopback', 'no_port', 'out_of_memory_build',
        'out_of_memory', 'jvm_out_of_memory', 'killed', 'crashed_signal', 'rails_master_key', 'rails_server_running', 'django_disallowed_host', 'rails_blocked_host', 'csrf_origin', 'phoenix_check_origin', 'user_switch', 'volume_permission', 'port_in_use', 'database_unreachable', 'module_not_found', 'gunicorn_timeout', 'disk_full_runtime',
        'pnpm_lockfile', 'npm_lockfile', 'yarn_lockfile', 'php_extension', 'native_module',
        'build_disk_full', 'build_dns', 'build_database', 'build_idle', 'build_module_not_found', 'build_rate_limited', 'django_settings_build',
        'missing_variable', 'health_status', 'health_not_http', 'health_refused', 'health_timeout',
    ]);

    $explanation = FailureCatalogue::explain(new FailureContext(F::HealthTimeout, 'x'));
    $copy = strtolower(($explanation?->title ?? '').($explanation?->cause ?? '').($explanation?->fix ?? ''));
    expect($copy)->not->toBe('')->not->toContain('agent')->not->toContain('daemon');
});

test('every typed start failure and every new build hint has an entry', function (): void {
    foreach (WorkloadFailureCode::cases() as $code) {
        if ($code === WorkloadFailureCode::Other) {
            continue;
        }

        $explanation = FailureCatalogue::explain(new FailureContext(F::RuntimeError, 'x', hintCodes: [$code->value]));
        expect($explanation)->not->toBeNull("{$code->value} has no entry");
    }

    foreach ([BuildHintCode::DiskFull, BuildHintCode::BuildDns, BuildHintCode::BuildDatabase, BuildHintCode::BuildIdle, BuildHintCode::MissingModule, BuildHintCode::RegistryRateLimit] as $hint) {
        $explanation = FailureCatalogue::explain(new FailureContext(F::BuildError, 'x', hintCodes: [$hint->value]));
        expect($explanation)->not->toBeNull("{$hint->value} has no entry");
    }

    expect(FailureCatalogue::explain(new FailureContext(F::RuntimeError, 'x', hintCodes: [ProbeHint::HostNotAllowed->value]))?->key)->toBe('host_not_allowed');
});

test('catalogue copy follows the voice rules', function (FailureContext $context): void {
    $explanation = FailureCatalogue::explain($context);
    $copy = ($explanation?->title ?? '').' '.($explanation?->cause ?? '').' '.($explanation?->fix ?? '');

    expect($copy)->not->toMatch('/\bagent\b|\bdaemon\b|—|\bjust\b|\bsimply\b|\beasy\b/i');
})->with('failures');
