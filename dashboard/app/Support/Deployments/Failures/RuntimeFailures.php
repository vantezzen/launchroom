<?php

declare(strict_types=1);

namespace App\Support\Deployments\Failures;

use App\Data\Deployments\FailureExplanationData;
use App\Enums\DeploymentFailureClass;
use App\Enums\FailureFixAction;
use App\Enums\ProbeHint;
use App\Enums\WorkloadFailureCode;
use App\Support\Deployments\ExitMeaning;

/**
 * The catalogue's entries for a container that didn't start or didn't stay
 * up (G5, G6, G11): first launchroomd's typed start and pull failures
 * (WorkloadFailureCode) and probe hint, then what the exit code means, then
 * what the app printed (a refused database, a folder it can't write to, a
 * user switch the hardening blocks, a framework that refuses its domain).
 * Same shape as FailureCatalogue's own entries; `when` matches on the
 * context's typed facts.
 *
 * @phpstan-import-type Entry from FailureCatalogue
 */
final class RuntimeFailures
{
    private const string DB_PORTS = '(5432|3306|6379|27017|5672|9200)';

    /**
     * Typed start and pull failures and the probe hint: matched on codes
     * only, before anything else.
     *
     * @return list<Entry>
     */
    public static function typed(): array
    {
        return [
            [
                'key' => 'command_not_found',
                'classes' => null,
                'hints' => [WorkloadFailureCode::CommandNotFound->value],
                'patterns' => [],
                'when' => static fn (FailureContext $c): bool => $c->exitCode === 127 && ! $c->failureCode() instanceof WorkloadFailureCode && $c->class !== DeploymentFailureClass::BuildError,
                'explain' => static fn (FailureMatch $m, FailureContext $c): FailureExplanationData => new FailureExplanationData(
                    key: 'command_not_found',
                    title: "The start command doesn't exist in the image",
                    cause: "{$c->serverName()} tried to run the start command, but the image has no such program (exit code 127).",
                    fix: 'Check the start command in Settings → Deploy, and that the program is installed in the image.',
                ),
            ],
            [
                'key' => 'not_executable',
                'classes' => null,
                'hints' => [WorkloadFailureCode::NotExecutable->value],
                'patterns' => [],
                'when' => static fn (FailureContext $c): bool => $c->exitCode === 126 && ! $c->failureCode() instanceof WorkloadFailureCode && $c->class !== DeploymentFailureClass::BuildError,
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'not_executable',
                    title: "The start command can't be run",
                    cause: 'The file the start command points at is in the image, but it isn’t executable (exit code 126).',
                    fix: 'Make it executable with chmod +x in the Dockerfile, or start it through its interpreter, for example sh start.sh.',
                ),
            ],
            [
                'key' => 'wrong_platform',
                'classes' => null,
                'hints' => [WorkloadFailureCode::WrongPlatform->value],
                'patterns' => [],
                'extract' => '/built for ([a-z0-9\/]+)[;,] [^\n]*?runs ([a-z0-9\/]+)/i',
                'explain' => static function (FailureMatch $m, FailureContext $c): FailureExplanationData {
                    $image = $m->group(1);
                    $server = $m->group(2);

                    return new FailureExplanationData(
                        key: 'wrong_platform',
                        title: 'The image is built for a different processor',
                        cause: $image !== '' && $server !== ''
                            ? "The image is built for {$image}, and {$c->serverName(false)} runs {$server}."
                            : "The image isn’t built for {$c->serverName(false)}’s processor.",
                        fix: 'Use a tag of the image that is published for several platforms, or let launchroom build the service from its repository on the server.',
                    );
                },
            ],
            [
                'key' => 'mount_failed',
                'classes' => null,
                'hints' => [WorkloadFailureCode::MountFailed->value],
                'patterns' => [],
                'explain' => static fn (FailureMatch $m, FailureContext $c): FailureExplanationData => new FailureExplanationData(
                    key: 'mount_failed',
                    title: "A volume couldn't be attached",
                    cause: "{$c->serverName()} couldn’t attach one of the service’s volumes when it created the container.",
                    fix: 'Check the volume paths in Settings → Volumes. A path that is a file in the image can’t hold a volume.',
                ),
            ],
            [
                'key' => 'invalid_user',
                'classes' => null,
                'hints' => [WorkloadFailureCode::InvalidUser->value],
                'patterns' => [],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'invalid_user',
                    title: "The image's user doesn't exist",
                    cause: 'The image says to run as a user that isn’t in the image, so the container can’t start.',
                    fix: 'Fix the USER line in the Dockerfile, or create that user in the image.',
                ),
            ],
            [
                'key' => 'image_not_found',
                'classes' => null,
                'hints' => [WorkloadFailureCode::ImageNotFound->value],
                'patterns' => [],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'image_not_found',
                    title: "The image doesn't exist",
                    cause: 'The registry has no image with this name and tag.',
                    fix: 'Check the image name and tag in Settings → Source, then deploy again.',
                ),
            ],
            [
                'key' => 'image_unauthorized',
                'classes' => null,
                'hints' => [WorkloadFailureCode::Unauthorized->value],
                'patterns' => [],
                'explain' => static fn (FailureMatch $m, FailureContext $c): FailureExplanationData => new FailureExplanationData(
                    key: 'image_unauthorized',
                    title: 'The image is private or doesn’t exist',
                    cause: "The registry refused to send the image to {$c->serverName(false)}.",
                    fix: 'Check the image name and tag. If the image is private, add the registry’s sign-in details in Settings → Private registries, then deploy again.',
                ),
            ],
            [
                'key' => 'image_rate_limited',
                'classes' => null,
                'hints' => [WorkloadFailureCode::RateLimited->value],
                'patterns' => [],
                'explain' => static fn (FailureMatch $m, FailureContext $c): FailureExplanationData => new FailureExplanationData(
                    key: 'image_rate_limited',
                    title: 'The registry’s download limit was reached',
                    cause: "Docker Hub limits how many images one address may download without signing in, and {$c->serverName(false)} reached that limit.",
                    fix: 'Deploy again in a few hours, or add Docker Hub sign-in details in Settings → Private registries so downloads count against your account.',
                ),
            ],
            [
                'key' => 'image_network',
                'classes' => null,
                'hints' => [WorkloadFailureCode::Network->value],
                'patterns' => [],
                'explain' => static fn (FailureMatch $m, FailureContext $c): FailureExplanationData => new FailureExplanationData(
                    key: 'image_network',
                    title: "{$c->serverName()} couldn't reach the registry",
                    cause: 'Downloading the image timed out, or the registry’s address couldn’t be looked up.',
                    fix: 'Check the server’s internet connection and name resolution on its page, then deploy again.',
                ),
            ],
            [
                'key' => 'no_space',
                'classes' => null,
                'hints' => [WorkloadFailureCode::NoSpace->value],
                'patterns' => [],
                'explain' => static fn (FailureMatch $m, FailureContext $c): FailureExplanationData => self::diskFull('no_space', $c, "There was no space left on {$c->serverName(false)} to download or start the image."),
            ],
            [
                'key' => 'host_not_allowed',
                'classes' => null,
                'hints' => [ProbeHint::HostNotAllowed->value],
                'patterns' => [],
                'extract' => '/HTTP (\d{3})/',
                'explain' => static function (FailureMatch $m): FailureExplanationData {
                    $status = $m->group(1);

                    return new FailureExplanationData(
                        key: 'host_not_allowed',
                        title: 'Your app refuses its own domain',
                        cause: 'The health check asked for the service’s domain and the app answered'.($status === '' ? '' : " HTTP {$status}").' with a “host not allowed” page, so visitors get that page too.',
                        fix: 'Add the domain to the app’s allowed hosts (ALLOWED_HOSTS in Django, config.hosts in Rails, trusted hosts in Symfony or Laravel), or read them from the ALLOWED_HOSTS variable launchroom sets for detected frameworks. Then deploy again.',
                    );
                },
            ],
        ];
    }

    /**
     * Exit codes with a clear meaning, then the app's own output.
     *
     * @return list<Entry>
     */
    public static function output(): array
    {
        $runtime = [DeploymentFailureClass::HealthTimeout, DeploymentFailureClass::RuntimeError, DeploymentFailureClass::PortMismatch, DeploymentFailureClass::ReleaseError];

        return [
            [
                'key' => 'out_of_memory',
                'classes' => null,
                'hints' => [],
                // launchroomd's own words, written only with the OOM flag ("killed: out of memory").
                'patterns' => ['/killed: out of memory|OOM ?killed|Out of memory: the container was killed|keeps running out of memory/i'],
                'when' => static fn (FailureContext $c): bool => $c->oomKilled,
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'out_of_memory',
                    title: 'Your app ran out of memory',
                    cause: 'It used more memory than its limit and was stopped.',
                    fix: 'Raise the memory limit in the service settings under Scaling, or reduce what the app keeps in memory.',
                ),
            ],
            [
                'key' => 'jvm_out_of_memory',
                'classes' => $runtime,
                'hints' => [],
                'patterns' => ['/java\.lang\.OutOfMemoryError(?:: ([^\n]+))?/'],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'jvm_out_of_memory',
                    title: 'The JVM ran out of memory',
                    cause: 'Java used up the heap it was given and threw OutOfMemoryError.',
                    fix: 'Raise the memory limit in Settings → Scaling. The JVM sizes its heap from that limit.',
                ),
            ],
            [
                'key' => 'killed',
                'classes' => $runtime,
                'hints' => [],
                'patterns' => [],
                'when' => static fn (FailureContext $c): bool => ! $c->oomKilled && ! $c->byLaunchroom && ($c->exitCode === 137 || $c->signal === 9),
                'explain' => static fn (FailureMatch $m, FailureContext $c): FailureExplanationData => new FailureExplanationData(
                    key: 'killed',
                    title: 'Your app was killed',
                    cause: 'It ended with SIGKILL (exit code 137). launchroom didn’t stop it, and couldn’t confirm that it ran out of memory.',
                    fix: "Check {$c->serverName(false)}’s memory on its page and the last lines below. If memory is tight, raise the limit in Settings → Scaling.",
                ),
            ],
            [
                'key' => 'crashed_signal',
                'classes' => $runtime,
                'hints' => [],
                'patterns' => [],
                'when' => static fn (FailureContext $c): bool => in_array($c->signal ?? (($c->exitCode ?? 0) > 128 ? ($c->exitCode ?? 0) - 128 : null), [4, 6, 7, 11], true),
                'explain' => static fn (FailureMatch $m, FailureContext $c): FailureExplanationData => new FailureExplanationData(
                    key: 'crashed_signal',
                    title: 'Your app crashed',
                    cause: ucfirst((string) ExitMeaning::of($c->exitCode, false, $c->signal)).'. This usually comes from a native library: built for another system, or a bug in it.',
                    fix: 'Check the last lines for the library that crashed. Rebuilding native modules for the server’s platform often fixes it.',
                ),
            ],
            [
                'key' => 'rails_master_key',
                'classes' => null,
                'hints' => [],
                'patterns' => ['/Missing encryption key to decrypt|ActiveSupport::MessageEncryptor::InvalidMessage|Missing `secret_key_base`/'],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'rails_master_key',
                    title: "Rails can't read its credentials",
                    cause: 'RAILS_MASTER_KEY is missing or doesn’t match config/credentials.yml.enc, so Rails can’t decrypt its secrets.',
                    fix: 'Add RAILS_MASTER_KEY as a secret with the contents of config/master.key, then deploy again.',
                    action: FailureFixAction::AddVariable,
                    params: ['name' => 'RAILS_MASTER_KEY', 'type' => 'secret'],
                ),
            ],
            [
                'key' => 'rails_server_running',
                'classes' => $runtime,
                'hints' => [],
                'patterns' => ['/A server is already running/'],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'rails_server_running',
                    title: 'Rails found an old server.pid',
                    cause: 'The image contains tmp/pids/server.pid from an earlier run, so Rails thinks it is already running.',
                    fix: 'Delete tmp/pids/server.pid before the server starts, for example rm -f tmp/pids/server.pid in the start command, or keep launchroom’s Rails start command.',
                ),
            ],
            [
                'key' => 'django_disallowed_host',
                'classes' => null,
                'hints' => [],
                'patterns' => ['/DisallowedHost|Invalid HTTP_HOST header/'],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'django_disallowed_host',
                    title: 'Django refuses this domain',
                    cause: 'ALLOWED_HOSTS in the Django settings doesn’t list the domain the request used.',
                    fix: 'Read ALLOWED_HOSTS from the ALLOWED_HOSTS variable launchroom sets, or keep launchroom’s allowed-hosts setting turned on in Settings → Build.',
                ),
            ],
            [
                'key' => 'rails_blocked_host',
                'classes' => null,
                'hints' => [],
                'patterns' => ['/Blocked hosts?: ([^\s]+)/'],
                'explain' => static fn (FailureMatch $m): FailureExplanationData => new FailureExplanationData(
                    key: 'rails_blocked_host',
                    title: 'Rails refuses this domain',
                    cause: 'Rails’ host authorization blocked '.($m->firstGroup() === '' ? 'the domain' : $m->firstGroup()).', because config.hosts doesn’t list it.',
                    fix: 'Add the domain to config.hosts in config/environments/production.rb, or read it from the DOMAIN variable launchroom sets.',
                ),
            ],
            [
                'key' => 'csrf_origin',
                'classes' => null,
                'hints' => [],
                'patterns' => ['/CSRF verification failed|Origin checking failed - ([^\s]+) does not match|Forbidden \(CSRF|Can\'t verify CSRF token authenticity|HTTP Origin header \(([^)]+)\) didn\'t match/'],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'csrf_origin',
                    title: 'Form posts are refused as cross-site',
                    cause: 'The app’s CSRF protection rejects forms sent from its own domain, because that domain isn’t one of its trusted origins.',
                    fix: 'Add the domain to the trusted origins (CSRF_TRUSTED_ORIGINS in Django, or read it from the PUBLIC_URL variable launchroom sets), then deploy again.',
                ),
            ],
            [
                'key' => 'phoenix_check_origin',
                'classes' => null,
                'hints' => [],
                'patterns' => ['/Could not check origin for Phoenix\.Socket transport/'],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'phoenix_check_origin',
                    title: "LiveView can't connect from this domain",
                    cause: 'Phoenix refused the browser’s socket because the page’s domain isn’t PHX_HOST or in check_origin.',
                    fix: 'Set check_origin in the endpoint config to the domains you use, or let PHX_HOST follow the service’s domain (launchroom sets it for Phoenix apps).',
                ),
            ],
            [
                'key' => 'user_switch',
                'classes' => null,
                'hints' => [],
                'patterns' => ['/(chown|setgid|setuid|setgroups|initgroups|su-exec|gosu)[^\n]*(Operation not permitted|EPERM)/i'],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'user_switch',
                    title: 'Your app needs to switch users',
                    cause: 'The image starts as root and then switches to another user (as nginx, gosu and su-exec do). launchroom drops that permission by default.',
                    fix: 'Allow it to switch users: launchroom adds only the permissions for changing users and file owners, keeps everything else dropped, and deploys again.',
                    action: FailureFixAction::AddCapabilities,
                ),
            ],
            [
                'key' => 'volume_permission',
                'classes' => $runtime,
                'hints' => [],
                'patterns' => [
                    "/EACCES: permission denied, \\w+ '([^']+)'/",
                    "/PermissionError: \\[Errno 13\\] Permission denied: '([^']+)'/",
                    '/could not (?:open|create) (?:file|directory) "([^"]+)": Permission denied/',
                    '/(?:fopen|file_put_contents|mkdir)\(([^)]+)\): (?:F|f)ailed to open stream: Permission denied/',
                    '/The stream or file "([^"]+)" could not be opened[^\n]*Permission denied/',
                    '/(\/[\w.\/-]+): Permission denied/',
                ],
                'explain' => static function (FailureMatch $m, FailureContext $c): FailureExplanationData {
                    $path = $m->firstGroup();
                    $uid = $c->numericImageUser();

                    return new FailureExplanationData(
                        key: 'volume_permission',
                        title: $path === '' ? "Your app can't write to a folder" : "Your app can't write to {$path}",
                        cause: 'The app runs as a user that doesn’t own this folder. A new volume belongs to root unless launchroom gives it to the app’s user.',
                        fix: $uid !== null
                            ? "launchroom can give a new, empty volume to the image’s user ({$uid}) before the app starts. For a folder in the image, or a volume that already has files, change the owner in the Dockerfile with chown."
                            : 'Give the folder to the app’s user in the Dockerfile (RUN mkdir -p … && chown …). If it is a volume, run the image as a numeric user (USER 1000) so launchroom can give a new volume to it.',
                        action: $uid !== null ? FailureFixAction::FixVolumeOwner : null,
                        params: $uid !== null ? ['uid' => $uid] : [],
                    );
                },
            ],
            [
                'key' => 'port_in_use',
                'classes' => $runtime,
                'hints' => [],
                'patterns' => ['/EADDRINUSE|Address already in use|address already in use/'],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'port_in_use',
                    title: 'The port is already taken inside the container',
                    cause: 'Two processes in the container try to listen on the same port, often because the start command starts the server twice.',
                    fix: 'Check the start command in Settings → Deploy. A worker of this app shouldn’t start the web server.',
                ),
            ],
            [
                'key' => 'database_unreachable',
                'classes' => $runtime,
                'hints' => [],
                'patterns' => [
                    '/ECONNREFUSED\s+\[?([0-9a-f.:]+?)\]?:'.self::DB_PORTS.'\b/i',
                    '/connect(?:ion)? (?:to )?(?:server at )?"?([\w.-]+)"?[^\n]{0,40}(?:port '.self::DB_PORTS.')[^\n]{0,40}(?:failed|refused)/i',
                    '/P1001: Can\'t reach database server at `?([\w.-]+)/',
                    '/SQLSTATE\[HY000\] \[2002\] (Connection refused|No such file or directory)/',
                    '/getaddrinfo (?:ENOTFOUND|EAI_AGAIN) (\S+)/',
                    '/could not translate host name "([^"]+)"/',
                ],
                'explain' => static function (FailureMatch $m): FailureExplanationData {
                    $host = $m->group(1);
                    $local = in_array($host, ['127.0.0.1', '::1', 'localhost', 'No such file or directory'], true);

                    return new FailureExplanationData(
                        key: 'database_unreachable',
                        title: "Your app can't reach its database",
                        cause: $local
                            ? 'The app connects to its database on localhost, which inside the container is the app itself.'
                            : 'The app tried to connect to '.($host === '' || $host === 'Connection refused' ? 'its database' : $host).' and nothing answered. The database may be stopped, or the address only works on your computer.',
                        fix: 'Connect the database to this service on the canvas so launchroom sets DATABASE_URL, or check the database’s host in the service’s variables.',
                    );
                },
            ],
            [
                'key' => 'module_not_found',
                'classes' => $runtime,
                'hints' => [],
                'patterns' => ["/Cannot find (?:module|package) '([^']+)'/", '/ERR_MODULE_NOT_FOUND[^\n]*?\'([^\']+)\'/'],
                'explain' => static function (FailureMatch $m): FailureExplanationData {
                    $module = $m->firstGroup();

                    return new FailureExplanationData(
                        key: 'module_not_found',
                        title: $module === '' ? 'A module is missing from the image' : "The module {$module} isn't in the image",
                        cause: 'The app loads it when it starts, but the built image doesn’t have it. Usually the package is only in devDependencies, or the start command points at a file the build didn’t produce.',
                        fix: 'Move the package to dependencies in package.json, or check that the start command in Settings → Deploy points at the built file.',
                    );
                },
            ],
            [
                'key' => 'gunicorn_timeout',
                'classes' => $runtime,
                'hints' => [],
                'patterns' => ['/WORKER TIMEOUT/'],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'gunicorn_timeout',
                    title: 'A gunicorn worker took too long',
                    cause: 'gunicorn stopped a worker that didn’t answer within its timeout (30 seconds by default).',
                    fix: 'Find the slow request in the logs, or raise the timeout with GUNICORN_CMD_ARGS="--timeout 120".',
                    action: FailureFixAction::AddVariable,
                    params: ['name' => 'GUNICORN_CMD_ARGS', 'value' => '--timeout 120'],
                ),
            ],
            [
                'key' => 'disk_full_runtime',
                'classes' => $runtime,
                'hints' => [],
                'patterns' => ['/ENOSPC|No space left on device/'],
                'explain' => static fn (FailureMatch $m, FailureContext $c): FailureExplanationData => self::diskFull('disk_full_runtime', $c, "The app couldn’t write a file because {$c->serverName(false)}’s disk is full."),
            ],
        ];
    }

    /**
     * A health check that got an answer, but not the expected one (G46).
     *
     * @return list<Entry>
     */
    public static function probe(): array
    {
        return [
            [
                'key' => 'health_status',
                'classes' => [DeploymentFailureClass::HealthTimeout],
                'hints' => [],
                'patterns' => ['/HTTP (4\d\d|5\d\d) \(expected ([0-9x-]+)\)/'],
                'explain' => static function (FailureMatch $m): FailureExplanationData {
                    $status = (int) $m->group(1);

                    if ($status >= 500) {
                        return new FailureExplanationData(
                            key: 'health_status',
                            title: "Your app answers the health check with HTTP {$status}",
                            cause: 'The app is running, but the page it checks fails with a server error.',
                            fix: 'The error is in the app’s output below. If it needs a database or a variable, set that up and deploy again.',
                        );
                    }

                    return new FailureExplanationData(
                        key: 'health_status',
                        title: "Your app answers the health check with HTTP {$status}",
                        cause: 'The app is running, but the health check path has no page that answers with 2xx or 3xx.',
                        fix: 'Point the health check at a page that exists (such as /health or /up) in Settings → Deploy, or accept 4xx answers as healthy.',
                        action: FailureFixAction::Accept4xx,
                    );
                },
            ],
            [
                'key' => 'health_not_http',
                'classes' => [DeploymentFailureClass::HealthTimeout],
                'hints' => [],
                'patterns' => ['/malformed HTTP response|\\((?:unexpected )?EOF[,)]|: (?:unexpected )?EOF\\b|connection reset by peer/i'],
                'explain' => static fn (): FailureExplanationData => new FailureExplanationData(
                    key: 'health_not_http',
                    title: 'Your app doesn’t answer HTTP on its port',
                    cause: 'Something accepts connections on the port, but it doesn’t answer like a web server. Databases, queues and other services speak their own protocol.',
                    fix: 'Switch the health check to TCP, which only checks that the port accepts connections.',
                    action: FailureFixAction::UseTcpHealthcheck,
                ),
            ],
        ];
    }

    private static function diskFull(string $key, FailureContext $c, string $cause): FailureExplanationData
    {
        return new FailureExplanationData(
            key: $key,
            title: "{$c->serverName()}'s disk is full",
            cause: $cause,
            fix: 'Free space on the server: launchroom removes unused images, old build cache and stopped containers. Then deploy again. If it fills up again, the server needs a bigger disk.',
            action: $c->serverId === null ? null : FailureFixAction::FreeDisk,
            params: $c->serverId === null ? [] : ['server' => $c->serverId, 'serverName' => $c->serverName()],
        );
    }
}
