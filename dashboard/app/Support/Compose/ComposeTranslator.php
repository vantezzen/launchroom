<?php

declare(strict_types=1);

namespace App\Support\Compose;

use App\Data\Blueprints\BlueprintBuild;
use App\Data\Blueprints\BlueprintDatabase;
use App\Data\Blueprints\BlueprintNote;
use App\Data\Blueprints\BlueprintService;
use App\Data\Blueprints\BlueprintVariable;
use App\Data\Blueprints\ComposeInputData;
use App\Data\Blueprints\ProjectBlueprint;
use App\Data\Blueprints\ServiceChoiceData;
use App\Data\Blueprints\SkippedServiceData;
use App\Data\Services\HealthcheckSettings;
use App\Enums\BlueprintNoteKind as Note;
use App\Enums\ConnectionStyle;
use App\Enums\DatabaseEngine;
use App\Enums\HealthcheckType;
use App\Enums\ServiceKind;
use App\Enums\VariableExposure;
use App\Enums\VariableType;
use App\Support\Blueprints\BlueprintValidator;
use App\Support\Canvas\ServiceGlyph;
use App\Support\Images\ImageReference;
use App\Support\Slug;
use App\Support\Variables\EnvFileParser;
use InvalidArgumentException;
use LogicException;

/**
 * The Compose allow-list translator (docs/17 §2.3): turns a parsed Compose
 * file into a valid ProjectBlueprint plus a note for every line it used,
 * ignored or refused. Pure: no I/O, nothing is passed to `docker compose`
 * (docs/09 R6). Anything not listed here is ignored with a note; refused
 * fields are simply not applied.
 */
final class ComposeTranslator
{
    public const int MAX_SERVICES = 50;

    /** launchroomd's capability allow-list (docs/03 §8). */
    public const array CAPABILITIES = BlueprintValidator::CAPABILITIES;

    /** Upstreams of the registry mirror (dev/zot, deploy/images/zot). */
    public const array REGISTRIES = ['docker.io', 'ghcr.io'];

    /** Service keys that are refused, with the reason (docs/17 §2.3). */
    private const array REFUSED = [
        'privileged' => 'privileged is refused: services never get host access.',
        'pid' => 'pid is refused: services never share the host’s processes.',
        'ipc' => 'ipc is refused: services never share the host’s IPC namespace.',
        'uts' => 'uts is refused: services never share the host’s namespaces.',
        'userns_mode' => 'userns_mode is refused: services never share the host’s user namespace.',
        'cgroup' => 'cgroup is refused: services never share the host’s cgroups.',
        'cgroup_parent' => 'cgroup_parent is refused: launchroom sets limits itself.',
        'devices' => 'devices is refused: services never get host devices.',
        'device_cgroup_rules' => 'device_cgroup_rules is refused: services never get host devices.',
        'gpus' => 'gpus is refused for now: GPUs need a local policy on the server.',
        'sysctls' => 'sysctls is refused: kernel settings stay the server’s.',
        'security_opt' => 'security_opt is refused: seccomp and AppArmor always stay on.',
        'extra_hosts' => 'extra_hosts is refused: services reach each other by name.',
        'external_links' => 'external_links is refused: services only reach their own environment.',
        'volumes_from' => 'volumes_from is refused: each volume belongs to one service.',
        'isolation' => 'isolation is refused.',
        'runtime' => 'runtime is refused: services run with the standard runtime.',
        'storage_opt' => 'storage_opt is refused.',
        'group_add' => 'group_add is refused: services run as the image’s user.',
        'mac_address' => 'mac_address is refused.',
        'oom_kill_disable' => 'oom_kill_disable is refused: memory limits always apply.',
        'secrets' => 'secrets is refused: use a secret variable instead.',
        'configs' => 'configs is refused: build the file into the image or use a variable.',
        'extends' => 'extends is refused: write the service out in this file.',
        'post_start' => 'post_start is refused: lifecycle hooks aren’t run.',
        'pre_stop' => 'pre_stop is refused: lifecycle hooks aren’t run.',
        'credential_spec' => 'credential_spec is refused.',
        'use_api_socket' => 'use_api_socket is refused: services never reach the Docker API.',
    ];

    /** Service keys that are ignored, with what launchroom does instead. */
    private const array IGNORED = [
        'restart' => 'launchroom always restarts services.',
        'labels' => 'Labels are ignored: launchroom routes with its own domains.',
        'logging' => 'launchroom keeps and rotates logs itself.',
        'stop_signal' => 'stop_signal is ignored: launchroom stops with SIGTERM.',
        'stop_grace_period' => 'stop_grace_period is ignored: launchroom drains traffic before stopping.',
        'tty' => 'tty is ignored.',
        'stdin_open' => 'stdin_open is ignored.',
        'init' => 'launchroom always runs an init process.',
        'pull_policy' => 'launchroom pins the image when it deploys.',
        'platform' => 'platform is ignored: launchroom picks the server’s platform.',
        'working_dir' => 'working_dir is ignored: the service runs in the image’s working directory.',
        'user' => 'user is ignored: the service runs as the image’s user.',
        'read_only' => 'read_only is ignored.',
        'shm_size' => 'shm_size is ignored.',
        'ulimits' => 'ulimits is ignored.',
        'tmpfs' => 'tmpfs is ignored.',
        'domainname' => 'domainname is ignored.',
        'dns' => 'dns is ignored: launchroom resolves service names itself.',
        'dns_search' => 'dns_search is ignored.',
        'dns_opt' => 'dns_opt is ignored.',
        'cpu_shares' => 'cpu_shares is ignored.',
        'cpu_quota' => 'cpu_quota is ignored.',
        'cpu_period' => 'cpu_period is ignored.',
        'cpu_count' => 'cpu_count is ignored.',
        'cpu_percent' => 'cpu_percent is ignored.',
        'cpu_rt_runtime' => 'cpu_rt_runtime is ignored.',
        'cpu_rt_period' => 'cpu_rt_period is ignored.',
        'cpuset' => 'cpuset is ignored.',
        'mem_reservation' => 'mem_reservation is ignored.',
        'mem_swappiness' => 'mem_swappiness is ignored.',
        'memswap_limit' => 'memswap_limit is ignored.',
        'blkio_config' => 'blkio_config is ignored.',
        'oom_score_adj' => 'oom_score_adj is ignored.',
        'pids_limit' => 'launchroom sets the process limit from the memory limit.',
        'develop' => 'develop is ignored.',
        'annotations' => 'annotations is ignored.',
        'attach' => 'attach is ignored.',
        'container_name' => 'launchroom names containers; other services reach it by its service name.',
        'hostname' => 'launchroom names containers; other services reach it by its service name.',
        'networks' => 'Every service of an environment shares one private network.',
        'links' => 'Services reach each other by name without links.',
        'cap_drop' => 'launchroom already drops every capability.',
        'expose' => '',
        'profiles' => '',
        'label_file' => 'label_file is ignored.',
        'scale' => '',
    ];

    /** Container ports that aren't HTTP: never routed to a domain. */
    private const array NON_HTTP = [
        21 => 'FTP', 22 => 'SSH', 25 => 'SMTP', 53 => 'DNS', 110 => 'POP3', 143 => 'IMAP', 465 => 'SMTP', 587 => 'SMTP',
        993 => 'IMAP', 995 => 'POP3', 1883 => 'MQTT', 3306 => 'MySQL', 5432 => 'PostgreSQL', 5672 => 'AMQP',
        6379 => 'Redis', 11211 => 'memcached', 27017 => 'MongoDB', 2222 => 'SSH',
    ];

    /** Credentials of database images (docs/17 §2.3): role => [env names, export key]. */
    private const array CREDENTIALS = [
        'postgres' => [
            'user' => [['POSTGRES_USER', 'POSTGRESQL_USERNAME'], 'PGUSER'],
            'password' => [['POSTGRES_PASSWORD', 'POSTGRESQL_PASSWORD'], 'PGPASSWORD'],
            'database' => [['POSTGRES_DB', 'POSTGRESQL_DATABASE'], 'PGDATABASE'],
        ],
        'mysql' => [
            'user' => [['MYSQL_USER', 'MARIADB_USER'], 'MYSQL_USER'],
            'password' => [['MYSQL_PASSWORD', 'MARIADB_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'MARIADB_ROOT_PASSWORD'], 'MYSQL_PASSWORD'],
            'database' => [['MYSQL_DATABASE', 'MARIADB_DATABASE'], 'MYSQL_DATABASE'],
        ],
        'redis' => [
            'password' => [['REDIS_PASSWORD'], 'REDIS_PASSWORD'],
        ],
        'mongodb' => [
            'user' => [['MONGO_INITDB_ROOT_USERNAME', 'MONGODB_ROOT_USER', 'MONGODB_USERNAME'], 'MONGOUSER'],
            'password' => [['MONGO_INITDB_ROOT_PASSWORD', 'MONGODB_ROOT_PASSWORD', 'MONGODB_PASSWORD'], 'MONGOPASSWORD'],
            'database' => [['MONGO_INITDB_DATABASE', 'MONGODB_DATABASE'], 'MONGODATABASE'],
        ],
        'clickhouse' => [
            'user' => [['CLICKHOUSE_USER', 'CLICKHOUSE_ADMIN_USER'], 'CLICKHOUSE_USER'],
            'password' => [['CLICKHOUSE_PASSWORD', 'CLICKHOUSE_ADMIN_PASSWORD'], 'CLICKHOUSE_PASSWORD'],
            'database' => [['CLICKHOUSE_DB'], 'CLICKHOUSE_DATABASE'],
        ],
    ];

    private const array USER_EXPORT = ['postgres' => 'PGUSER', 'mysql' => 'MYSQL_USER', 'redis' => 'REDIS_USER', 'mongodb' => 'MONGOUSER', 'clickhouse' => 'CLICKHOUSE_USER'];

    private const array PASSWORD_EXPORT = ['postgres' => 'PGPASSWORD', 'mysql' => 'MYSQL_PASSWORD', 'redis' => 'REDIS_PASSWORD', 'mongodb' => 'MONGOPASSWORD', 'clickhouse' => 'CLICKHOUSE_PASSWORD'];

    private const array DATABASE_EXPORT = ['postgres' => 'PGDATABASE', 'mysql' => 'MYSQL_DATABASE', 'redis' => 'REDIS_DATABASE', 'mongodb' => 'MONGODATABASE', 'clickhouse' => 'CLICKHOUSE_DATABASE'];

    private const array HOST_EXPORT = ['postgres' => 'PGHOST', 'mysql' => 'MYSQLHOST', 'redis' => 'REDIS_HOST', 'mongodb' => 'MONGOHOST', 'clickhouse' => 'CLICKHOUSE_HOST'];

    private const array PORT_EXPORT = ['postgres' => 'PGPORT', 'mysql' => 'MYSQLPORT', 'redis' => 'REDIS_PORT', 'mongodb' => 'MONGOPORT', 'clickhouse' => 'CLICKHOUSE_PORT'];

    private const array ENGINE_PORTS = ['postgres' => 5432, 'mysql' => 3306, 'mariadb' => 3306, 'redis' => 6379, 'valkey' => 6379, 'mongodb' => 27017, 'clickhouse' => 8123];

    private const string ENV_KEY = '/^[A-Za-z_][A-Za-z0-9_]{0,255}$/D';

    private const string MOUNT_PATH = '/^\/[A-Za-z0-9._@+=,-]+(?:\/[A-Za-z0-9._@+=,-]+)*$/D';

    /** @var list<BlueprintNote> */
    private array $notes = [];

    /** @var array<string, ServiceDraft> by Compose name */
    private array $drafts = [];

    /** @var array<string, string> lower-case host alias => blueprint key */
    private array $hosts = [];

    /** @var array<string, string> top-level volume => owning blueprint key */
    private array $volumeOwners = [];

    /** @var list<string> */
    private array $missingFiles = [];

    /** @var list<SkippedServiceData> */
    private array $skipped = [];

    private readonly ComposeInterpolation $interpolation;

    private function __construct(
        private readonly TranslationOptions $options,
        private readonly YamlLineIndex $lines,
    ) {
        $this->interpolation = new ComposeInterpolation($options->values);
    }

    /**
     * @throws ComposeRejected
     */
    public static function translate(string $yaml, TranslationOptions $options = new TranslationOptions): ComposeTranslation
    {
        return self::translateData(ComposeYaml::parse($yaml), YamlLineIndex::of($yaml), $options);
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws ComposeRejected
     */
    public static function translateData(array $data, YamlLineIndex $lines, TranslationOptions $options = new TranslationOptions): ComposeTranslation
    {
        return new self($options, $lines)->run($data);
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws ComposeRejected
     */
    private function run(array $data): ComposeTranslation
    {
        $services = $data['services'] ?? null;

        if (! is_array($services) || $services === [] || array_is_list($services)) {
            throw new ComposeRejected('The file has no services.', $this->lines->line('services'));
        }

        if (count($services) > self::MAX_SERVICES) {
            throw new ComposeRejected('The file has more than '.self::MAX_SERVICES.' services.');
        }

        $this->topLevel($data);
        $this->prepare($services);

        foreach ($this->drafts as $draft) {
            $this->classify($draft);
        }

        $this->groupWorkers();

        foreach ($this->drafts as $draft) {
            $this->fields($draft);
        }

        foreach ($this->drafts as $draft) {
            $this->rewriteHosts($draft);
        }

        $this->convertCredentials();

        foreach ($this->drafts as $draft) {
            $this->connect($draft);
        }

        if ($this->drafts === []) {
            throw new ComposeRejected('None of the services can be imported: '.implode(' ', array_map(static fn (SkippedServiceData $skipped): string => "{$skipped->name}: {$skipped->reason}", $this->skipped)));
        }

        $blueprint = new ProjectBlueprint(
            name: $this->projectName($data),
            services: array_values(array_map(static fn (ServiceDraft $draft): BlueprintService => $draft->toBlueprint(), $this->drafts)),
            sharedVariables: $this->sharedVariables(),
        );

        $errors = BlueprintValidator::errors($blueprint);

        if ($errors !== []) {
            throw new LogicException('The Compose translation produced an invalid blueprint: '.implode(' ', $errors));
        }

        $choices = [];

        foreach ($this->drafts as $draft) {
            if ($draft->databaseImage !== null || $draft->bindMounts > 0) {
                $choices[$draft->key] = new ServiceChoiceData($draft->composeName, $draft->databaseImage, $draft->databaseImage === null ? null : $draft->managed, $draft->bindMounts);
            }
        }

        usort($this->notes, static fn (BlueprintNote $a, BlueprintNote $b): int => ($a->line ?? PHP_INT_MAX) <=> ($b->line ?? PHP_INT_MAX));

        return new ComposeTranslation($blueprint, $this->notes, $this->inputs($blueprint), array_values(array_unique($this->missingFiles)), $this->skipped, $choices);
    }

    /* ------------------------------------------------------------------ */
    /* Top level and service names */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function topLevel(array $data): void
    {
        foreach ($data as $key => $value) {
            $key = (string) $key;
            $line = $this->lines->line($key);

            match (true) {
                $key === 'services', $key === 'name', str_starts_with($key, 'x-') => null,
                $key === 'version' => $this->projectNote(Note::Ignored, '“version” is obsolete in Compose and not needed.', $key, $line),
                $key === 'networks' => $this->projectNote(Note::Ignored, 'Networks are ignored: every service of an environment shares one private network and reaches the others by name.', $key, $line),
                $key === 'volumes' => $this->topLevelVolumes($value),
                in_array($key, ['secrets', 'configs'], true) => $this->projectNote(Note::Refused, "Top-level {$key} are refused: use secret variables instead of files.", $key, $line),
                $key === 'include' => $this->projectNote(Note::Refused, 'include is refused: only this file is imported.', $key, $line),
                default => $this->projectNote(Note::Ignored, "launchroom doesn’t use “{$key}”.", $key, $line),
            };
        }
    }

    private function topLevelVolumes(mixed $volumes): null
    {
        if (! is_array($volumes)) {
            return null;
        }

        foreach ($volumes as $name => $definition) {
            $name = (string) $name;
            $path = "volumes.{$name}";

            if (! is_array($definition)) {
                continue;
            }

            if (($definition['external'] ?? false) !== false) {
                $this->projectNote(Note::Warning, "Volume {$name} is external: launchroom creates a new, empty volume instead.", $path.'.external', $this->lines->line($path.'.external'));
            }

            foreach (['driver', 'driver_opts'] as $option) {
                if (array_key_exists($option, $definition)) {
                    $this->projectNote(Note::Refused, "{$option} of volume {$name} is refused: launchroom volumes use the local driver (driver options can mount host paths).", "{$path}.{$option}", $this->lines->line("{$path}.{$option}"));
                }
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $services
     */
    private function prepare(array $services): void
    {
        $taken = Slug::RESERVED;

        foreach ($services as $name => $definition) {
            $name = (string) $name;
            $line = $this->lines->line("services.{$name}");

            if (! is_array($definition)) {
                $this->skipped[] = new SkippedServiceData($name, 'It isn’t a mapping of settings.', $line);

                continue;
            }

            if (isset($definition['profiles']) && $definition['profiles'] !== [] && ! $this->options->template) {
                $this->skipped[] = new SkippedServiceData($name, 'It only starts with a Compose profile.', $line);

                continue;
            }

            $key = Slug::unique(Slug::from($name, 'service'), $taken);
            $taken[] = $key;
            $draft = new ServiceDraft($name, $key, $name, $definition);
            $draft->aliases = [strtolower($name)];

            foreach (['container_name', 'hostname'] as $field) {
                if (is_string($definition[$field] ?? null) && $definition[$field] !== '') {
                    $draft->aliases[] = strtolower($definition[$field]);
                }
            }

            if (is_array($definition['networks'] ?? null) && ! array_is_list($definition['networks'])) {
                foreach ($definition['networks'] as $network) {
                    foreach (is_array($network) && is_array($network['aliases'] ?? null) ? $network['aliases'] : [] as $alias) {
                        if (is_string($alias)) {
                            $draft->aliases[] = strtolower($alias);
                        }
                    }
                }
            }

            $draft->aliases = array_values(array_unique($draft->aliases));

            foreach ($draft->aliases as $alias) {
                $this->hosts[$alias] ??= $key;
            }

            $this->drafts[$name] = $draft;
        }
    }

    /* ------------------------------------------------------------------ */
    /* What each service becomes */
    /* ------------------------------------------------------------------ */

    private function classify(ServiceDraft $draft): void
    {
        $def = $draft->definition;
        $base = "services.{$draft->composeName}";
        $extension = $this->extension($draft);

        if (is_array($extension['database'] ?? null)) {
            $engine = DatabaseEngine::tryFrom(is_string($extension['database']['engine'] ?? null) ? $extension['database']['engine'] : '');

            if ($engine !== null) {
                $requested = is_scalar($extension['database']['version'] ?? null) ? (string) $extension['database']['version'] : null;
                $this->managedDatabase($draft, $engine, $requested, $base);

                return;
            }
        }

        if (is_string($extension['parent'] ?? null) && isset($this->drafts[$extension['parent']])) {
            // A process of another service of the template (same build, own command).
            $draft->kind = ServiceKind::Worker;
            $draft->parent = $this->drafts[$extension['parent']]->key;
            $draft->logo = 'worker';

            return;
        }

        $image = null;

        if (array_key_exists('image', $def)) {
            $image = $this->scalarField($draft, 'image');

            if ($image === null) {
                $this->skip($draft, 'Its image can’t be read.', "{$base}.image");

                return;
            }
        }

        $build = array_key_exists('build', $def) || ($extension['source'] ?? null) === 'repository' ? $this->build($draft) : null;
        $reference = $image === null ? null : ImageReference::tryParse($image);

        if ($image !== null && ! $reference instanceof ImageReference) {
            $this->skip($draft, "“{$image}” isn’t a valid image reference.", "{$base}.image");

            return;
        }

        if ($reference instanceof ImageReference) {
            $engine = $this->databaseEngine($reference);

            if ($engine !== null) {
                $draft->databaseImage = $engine === 'mariadb' ? DatabaseEngine::MySql : DatabaseEngine::from($engine);
                $choice = $this->options->databases[$draft->composeName] ?? ($engine === 'mariadb' ? 'container' : 'managed');

                if ($choice === 'managed') {
                    $draft->managed = true;
                    $version = $engine === 'mariadb' ? null : $this->tagVersion($reference->tag);
                    $this->managedDatabase($draft, $draft->databaseImage, $version, $base, $engine === 'mariadb' ? 'MariaDB' : null);

                    return;
                }
            }
        }

        if ($build instanceof BlueprintBuild && $this->options->repository) {
            $draft->kind = ServiceKind::App;
            $draft->build = $build;
            $draft->note(Note::Translated, 'Built from '.($build->context === '' ? 'the repository root' : "./{$build->context}").($build->dockerfile !== null ? " with {$build->dockerfile}" : '').'.', "{$base}.build", $this->lines->line("{$base}.build"));

            if ($image !== null) {
                $draft->note(Note::Ignored, 'image is ignored: launchroom builds this service from the repository.', "{$base}.image", $this->lines->line("{$base}.image"));
            }

            return;
        }

        if (! $reference instanceof ImageReference) {
            $this->skip($draft, $build instanceof BlueprintBuild
                ? 'It is built from source: import from a repository to build it.'
                : 'It has no image to run.', "{$base}.build", $build instanceof BlueprintBuild);

            return;
        }

        if (! in_array($reference->registry, self::REGISTRIES, true)) {
            $this->skip($draft, "Images from {$reference->registry} can’t be pulled yet: launchroom mirrors Docker Hub and GitHub Container Registry.", "{$base}.image");

            return;
        }

        if ($build instanceof BlueprintBuild) {
            $draft->note(Note::Ignored, 'build is ignored: this import runs the image (import from a repository to build it).', "{$base}.build", $this->lines->line("{$base}.build"));
        }

        $draft->kind = ServiceKind::Image;
        $draft->image = $image;
        $draft->logo = ServiceGlyph::forImage($image);
        $note = 'Runs '.ServiceGlyph::shortImage($image).($reference->isPinned() ? ', pinned by digest.' : ', pinned to its digest when it deploys.');

        if ($draft->databaseImage instanceof DatabaseEngine) {
            $note = "Kept as a container ({$this->engineLabel($reference)}). A managed database adds backups, generated credentials and clean stops.";
            $draft->capabilities = ['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'SETGID', 'SETUID'];
            $draft->logo = $draft->databaseImage->value === 'mysql' && str_contains($reference->repository, 'mariadb') ? 'mariadb' : $draft->databaseImage->value;
        }

        $draft->note(Note::Translated, $note, "{$base}.image", $this->lines->line("{$base}.image"));
    }

    private function managedDatabase(ServiceDraft $draft, DatabaseEngine $engine, ?string $requested, string $base, ?string $from = null): void
    {
        $draft->kind = ServiceKind::Database;
        $draft->managed = true;
        $draft->logo = $engine->value;
        $versions = $this->options->engines[$engine->value]['versions'] ?? [];
        $default = $this->options->engines[$engine->value]['default'] ?? ($versions[0] ?? '1');
        $version = $default;

        if ($requested !== null && $versions !== []) {
            if (in_array($requested, $versions, true)) {
                $version = $requested;
            } else {
                // The oldest supported version at least as new as the one asked for, else the newest.
                $newer = array_filter($versions, static fn (string $candidate): bool => version_compare($candidate, $requested, '>='));
                $version = $newer === [] ? $versions[0] : end($newer);
            }
        }

        $draft->database = new BlueprintDatabase($engine, $version);
        $label = $engine->label().' '.$version;
        $message = $from !== null
            ? "Becomes a managed {$label} (instead of {$from}): launchroom creates its credentials, volume and backups."
            : "Becomes a managed {$label}: launchroom creates its credentials, volume and backups.";

        if ($requested !== null && $requested !== $version && $from === null) {
            $message .= " ({$engine->label()} {$requested} isn’t offered, so it uses {$version}.)";
        }

        $draft->note(Note::Translated, $message, "{$base}.image", $this->lines->line("{$base}.image") ?? $this->lines->line($base));
    }

    private function databaseEngine(ImageReference $reference): ?string
    {
        $repository = $reference->repository;

        return match ($repository) {
            'library/postgres', 'bitnami/postgresql' => 'postgres',
            'library/mysql', 'bitnami/mysql', 'mysql/mysql-server' => 'mysql',
            'library/mariadb', 'bitnami/mariadb' => 'mariadb',
            'library/redis', 'bitnami/redis' => 'redis',
            'valkey/valkey', 'bitnami/valkey' => 'valkey',
            'library/mongo', 'bitnami/mongodb', 'mongodb/mongodb-community-server' => 'mongodb',
            'clickhouse/clickhouse-server', 'yandex/clickhouse-server', 'bitnami/clickhouse' => 'clickhouse',
            default => null,
        };
    }

    private function engineLabel(ImageReference $reference): string
    {
        return ServiceGlyph::shortImage($reference->canonical());
    }

    private function tagVersion(?string $tag): ?string
    {
        if ($tag === null || preg_match('/^v?(\d+)(?:\.(\d+))?/', $tag, $m) !== 1) {
            return null;
        }

        return isset($m[2]) ? $m[1].'.'.$m[2] : $m[1];
    }

    private function build(ServiceDraft $draft): ?BlueprintBuild
    {
        $base = "services.{$draft->composeName}.build";
        $value = $draft->definition['build'] ?? null;
        $line = $this->lines->line($base);
        $context = '.';
        $dockerfile = null;

        if (is_string($value)) {
            $context = $value;
        } elseif (is_array($value)) {
            $context = is_string($value['context'] ?? null) ? $value['context'] : '.';
            $dockerfile = is_string($value['dockerfile'] ?? null) ? $value['dockerfile'] : null;

            foreach ($value as $option => $setting) {
                $option = (string) $option;
                $at = $this->lines->line("{$base}.{$option}");

                match ($option) {
                    'context', 'dockerfile' => null,
                    'dockerfile_inline' => $draft->note(Note::Refused, 'dockerfile_inline is refused: keep the Dockerfile in the repository.', "{$base}.{$option}", $at),
                    'args' => $draft->note(Note::Ignored, 'Build args are ignored: add them as variables (they reach the build when it needs them).', "{$base}.{$option}", $at),
                    'target' => $draft->note(Note::Ignored, 'target is ignored: the Dockerfile’s last stage is built.', "{$base}.{$option}", $at),
                    default => $draft->note(Note::Ignored, "build.{$option} is ignored.", "{$base}.{$option}", $at),
                };
            }
        } elseif ($value !== null) {
            $draft->note(Note::Refused, 'build can’t be read.', $base, $line);

            return null;
        }

        try {
            $context = $this->interpolation->substitute($context);
        } catch (InterpolationFailed $e) {
            $draft->note(Note::Refused, $e->getMessage(), $base, $line);

            return null;
        }

        if (preg_match('#^(?:[a-z]+://|git@|github\.com/)#i', $context) === 1) {
            $draft->note(Note::Refused, 'Building from a URL is refused: launchroom builds from the repository you import.', $base, $line);

            return null;
        }

        $normalized = $this->relativePath($context);

        if ($normalized === null) {
            $draft->note(Note::Refused, "The build context {$context} is outside the repository.", $base, $line);

            return null;
        }

        if ($dockerfile !== null) {
            $dockerfile = $this->relativePath($dockerfile);

            if ($dockerfile === null || $dockerfile === '') {
                $draft->note(Note::Refused, 'The Dockerfile path is outside the build context.', "{$base}.dockerfile", $this->lines->line("{$base}.dockerfile"));
                $dockerfile = null;
            }
        }

        return new BlueprintBuild($normalized, $dockerfile);
    }

    /** `./api/` → `api`, `.` → ``; null for absolute paths or `..`. */
    private function relativePath(string $path): ?string
    {
        $path = trim($path);

        if (str_starts_with($path, '/') || str_starts_with($path, '~')) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..' || preg_match('/^[A-Za-z0-9_.@+=,-]+$/D', $segment) !== 1) {
                return null;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * Services built from the same context and Dockerfile with a different
     * command become workers of the one with ports (one build feeds both).
     */
    private function groupWorkers(): void
    {
        $groups = [];

        foreach ($this->drafts as $draft) {
            if ($draft->kind === ServiceKind::App && $draft->build instanceof BlueprintBuild) {
                $groups[$draft->build->context."\0".($draft->build->dockerfile ?? '')][] = $draft;
            }
        }

        foreach ($groups as $members) {
            if (count($members) < 2) {
                continue;
            }

            $parent = $members[0];

            foreach ($members as $member) {
                if (isset($member->definition['ports'])) {
                    $parent = $member;
                    break;
                }
            }

            foreach ($members as $member) {
                if ($member === $parent || ! isset($member->definition['command']) || $member->definition['command'] === ($parent->definition['command'] ?? null)) {
                    continue;
                }

                $member->kind = ServiceKind::Worker;
                $member->parent = $parent->key;
                $member->build = null;
                $member->logo = 'worker';
                $member->notes = array_values(array_filter($member->notes, static fn (BlueprintNote $note): bool => $note->field !== "services.{$member->composeName}.build"));
                $member->note(Note::Translated, "Becomes a worker of {$parent->name}: it runs the same build with its own command.", "services.{$member->composeName}.build", $this->lines->line("services.{$member->composeName}.build"));
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Fields */
    /* ------------------------------------------------------------------ */

    private function fields(ServiceDraft $draft): void
    {
        $def = $draft->definition;
        $base = "services.{$draft->composeName}";
        $managed = $draft->kind === ServiceKind::Database;

        $this->environment($draft);

        if ($managed) {
            $this->managedFields($draft);
            $this->templateExtension($draft);

            return;
        }

        $this->ports($draft);
        $this->proxyHints($draft);

        foreach ($def as $field => $value) {
            $field = (string) $field;
            $path = "{$base}.{$field}";
            $line = $this->lines->line($path);

            if (isset(self::REFUSED[$field])) {
                $draft->note(Note::Refused, self::REFUSED[$field], $path, $line);

                continue;
            }

            if (isset(self::IGNORED[$field])) {
                if (self::IGNORED[$field] !== '') {
                    $draft->note(Note::Ignored, self::IGNORED[$field], $path, $line);
                }

                continue;
            }

            switch ($field) {
                case 'image': case 'build': case 'ports': case 'environment': case 'env_file': case 'healthcheck':
                    break;
                case 'x-launchroom':
                    if (! $this->options->template) {
                        $draft->note(Note::Ignored, 'x-launchroom is only read in launchroom templates.', $path, $line);
                    }

                    break;
                case 'command':
                    $draft->command = $this->argv($draft, $field);
                    break;
                case 'entrypoint':
                    $draft->entrypoint = $this->argv($draft, $field);
                    break;
                case 'volumes':
                    $this->volumes($draft);
                    break;
                case 'depends_on':
                    $this->dependsOn($draft);
                    break;
                case 'deploy': case 'cpus': case 'mem_limit':
                    $this->resources($draft, $field);
                    break;
                case 'cap_add':
                    $this->capabilities($draft);
                    break;
                case 'network_mode':
                    $this->networkMode($draft, $value, $path, $line);
                    break;
                default:
                    if (! str_starts_with($field, 'x-')) {
                        $draft->note(Note::Ignored, "launchroom doesn’t use “{$field}”.", $path, $line);
                    }
            }
        }

        if (isset($def['scale']) && (! is_array($def['deploy'] ?? null) || ! isset($def['deploy']['replicas']))) {
            $this->replicas($draft, $def['scale'], "{$base}.scale");
        }

        if ($draft->databaseImage instanceof DatabaseEngine && $draft->port === null) {
            // A database kept as a container: private, on its engine's port.
            $draft->port = self::ENGINE_PORTS[$draft->databaseImage->value];
        }

        if ($draft->kind === ServiceKind::Worker) {
            $draft->port = null;
            $draft->public = false;
        }

        $this->healthcheck($draft);
        $this->templateExtension($draft);
    }

    private function managedFields(ServiceDraft $draft): void
    {
        $base = "services.{$draft->composeName}";
        $label = $draft->database?->engine->label() ?? 'the database';

        foreach ($draft->definition as $field => $value) {
            $field = (string) $field;
            $path = "{$base}.{$field}";
            $line = $this->lines->line($path);

            match (true) {
                in_array($field, ['image', 'container_name', 'hostname', 'networks', 'x-launchroom', 'environment', 'env_file', 'depends_on', 'links'], true), str_starts_with($field, 'x-') => null,
                $field === 'command' => $draft->note(Note::Ignored, "launchroom runs {$label} with its own settings.", $path, $line),
                in_array($field, ['ports', 'expose'], true) => $draft->note(Note::Ignored, 'Databases stay private: connect services to it on the canvas.', $path, $line),
                $field === 'volumes' => $draft->note(Note::Ignored, "launchroom gives {$label} its own volume (backed up with the database).", $path, $line),
                $field === 'healthcheck' => $draft->note(Note::Ignored, "launchroom checks {$label} itself.", $path, $line),
                isset(self::REFUSED[$field]) => $draft->note(Note::Refused, self::REFUSED[$field], $path, $line),
                default => $draft->note(Note::Ignored, "launchroom manages {$field} for databases.", $path, $line),
            };
        }

        $command = $draft->definition['command'] ?? null;

        if (is_string($command)) {
            $draft->rawCommand = preg_split('/\s+/', trim($command)) ?: [];
        } elseif (is_array($command)) {
            $draft->rawCommand = array_values(array_map(static fn (mixed $word): string => is_scalar($word) ? (string) $word : '', $command));
        }

        if ($draft->rawEnv !== []) {
            $draft->note(Note::Translated, 'Its credentials are generated per environment; services that used them get references instead.', "{$base}.environment", $this->lines->line("{$base}.environment") ?? $this->lines->line("{$base}.env_file"));
        }

        $draft->env = [];
    }

    /**
     * A reverse proxy in the file is launchroom's job (the edge); labels that
     * route a service through one make it public.
     */
    private function proxyHints(ServiceDraft $draft): void
    {
        $base = "services.{$draft->composeName}";

        if ($draft->image !== null && preg_match('#(?:^|/)(?:traefik|caddy|nginx-proxy|haproxy)(?::|@|$)#', $draft->image) === 1) {
            $draft->note(Note::Warning, 'launchroom already routes domains and certificates at its edge, so this proxy probably isn’t needed. Remove it after the import if so.', "{$base}.image", $this->lines->line("{$base}.image"));
        }

        $labels = $draft->definition['labels'] ?? null;
        $text = is_array($labels) ? implode("\n", array_map(static fn (mixed $key, mixed $value): string => is_int($key) ? (is_scalar($value) ? (string) $value : '') : $key.'='.(is_scalar($value) ? (string) $value : ''), array_keys($labels), $labels)) : '';

        if ($text !== '' && preg_match('/traefik\.enable=true|Host\(`/i', $text) === 1 && $draft->port !== null && ! $draft->public) {
            $draft->public = true;
            $draft->notes = array_values(array_filter($draft->notes, static fn (BlueprintNote $note): bool => ! str_starts_with($note->message, "Port {$draft->port} stays private")));
            $draft->note(Note::Translated, "Its proxy labels route it publicly, so port {$draft->port} gets a domain.", "{$base}.labels", $this->lines->line("{$base}.labels"));
        }

        if (preg_match_all('/Host\(`([^`]+)`\)/', $text, $hosts) > 0) {
            $draft->note(Note::Warning, 'Add '.implode(', ', array_unique($hosts[1])).' as a domain after the import (labels aren’t applied).', "{$base}.labels", $this->lines->line("{$base}.labels"));
        }
    }

    private function networkMode(ServiceDraft $draft, mixed $value, string $path, ?int $line): void
    {
        $mode = is_string($value) ? $value : '';

        match (true) {
            $mode === 'host' => $draft->note(Note::Refused, 'network_mode: host is refused: services never share the server’s network.', $path, $line),
            str_starts_with($mode, 'service:'), str_starts_with($mode, 'container:') => $draft->note(Note::Refused, "network_mode: {$mode} is refused: each service has its own network identity.", $path, $line),
            default => $draft->note(Note::Ignored, 'network_mode is ignored: the service joins its environment’s network.', $path, $line),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function extension(ServiceDraft $draft): array
    {
        if (! $this->options->template || ! is_array($draft->definition['x-launchroom'] ?? null)) {
            return [];
        }

        $extension = [];

        foreach ($draft->definition['x-launchroom'] as $key => $value) {
            $extension[(string) $key] = $value;
        }

        return $extension;
    }

    private function templateExtension(ServiceDraft $draft): void
    {
        $extension = $this->extension($draft);

        if (is_string($extension['name'] ?? null) && trim($extension['name']) !== '') {
            $draft->name = mb_substr(trim($extension['name']), 0, 63);
        }

        if (is_string($extension['logo'] ?? null)) {
            $draft->logo = $extension['logo'];
        }

        if (is_bool($extension['public'] ?? null)) {
            $draft->publicOverride = $extension['public'];
        }

        if (is_string($extension['domain'] ?? null) && trim($extension['domain']) !== '') {
            $draft->domain = strtolower(trim($extension['domain']));
        }

        if (is_array($extension['connect'] ?? null)) {
            foreach ($extension['connect'] as $to => $style) {
                $target = $this->drafts[(string) $to] ?? null;
                $style = ConnectionStyle::tryFrom(is_string($style) ? $style : '');

                if ($target !== null && $style !== null) {
                    $draft->connectStyles[$target->key] = $style;
                }
            }
        }
    }

    private function skip(ServiceDraft $draft, string $reason, string $path, bool $needsRepository = false): void
    {
        $this->skipped[] = new SkippedServiceData($draft->composeName, $reason, $this->lines->line($path) ?? $this->lines->line("services.{$draft->composeName}"), $needsRepository);

        foreach ($draft->aliases as $alias) {
            if (($this->hosts[$alias] ?? null) === $draft->key) {
                unset($this->hosts[$alias]);
            }
        }

        unset($this->drafts[$draft->composeName]);
    }

    /** A scalar field after interpolation (null when it can't be used). */
    private function scalarField(ServiceDraft $draft, string $field): ?string
    {
        $value = $draft->definition[$field] ?? null;
        $path = "services.{$draft->composeName}.{$field}";

        if (! is_scalar($value)) {
            return null;
        }

        try {
            return trim($this->interpolation->substitute((string) $value));
        } catch (InterpolationFailed $e) {
            $draft->note(Note::Refused, $e->getMessage(), $path, $this->lines->line($path));

            return null;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Ports and health checks */
    /* ------------------------------------------------------------------ */

    private function ports(ServiceDraft $draft): void
    {
        $base = "services.{$draft->composeName}";
        $published = [];
        $private = [];

        foreach (['ports' => true, 'expose' => false] as $field => $public) {
            $entries = $draft->definition[$field] ?? null;

            if ($entries === null) {
                continue;
            }

            if (! is_array($entries)) {
                $draft->note(Note::Refused, "{$field} can’t be read.", "{$base}.{$field}", $this->lines->line("{$base}.{$field}"));

                continue;
            }

            foreach (array_values($entries) as $index => $entry) {
                $path = "{$base}.{$field}.{$index}";
                $line = $this->lines->line($path);
                $parsed = $this->port($entry, $public);

                if (is_string($parsed)) {
                    $draft->note(Note::Refused, $parsed, $path, $line);

                    continue;
                }

                [$container, $host, $loopback] = $parsed;

                if (isset(self::NON_HTTP[$container])) {
                    $draft->note(Note::Refused, 'Port '.$container.' ('.self::NON_HTTP[$container].') isn’t routed: launchroom serves HTTP on domains. Other services still reach it as '.$draft->key.':'.$container.'.', $path, $line);
                    $private[] = ['port' => $container, 'path' => $path, 'line' => $line, 'routable' => false];

                    continue;
                }

                if ($public && ! $loopback) {
                    $published[] = ['port' => $container, 'host' => $host, 'path' => $path, 'line' => $line];
                } else {
                    $private[] = ['port' => $container, 'path' => $path, 'line' => $line, 'routable' => true, 'loopback' => $loopback];
                }
            }
        }

        $routable = array_values(array_filter($private, static fn (array $entry): bool => $entry['routable']));
        $chosen = $published[0] ?? $routable[0] ?? null;

        if ($chosen === null) {
            return;
        }

        $draft->port = $chosen['port'];
        $draft->public = isset($published[0]);
        $message = $draft->public
            ? 'Port '.$chosen['port'].' gets a domain'.(($chosen['host'] ?? null) !== null ? ' (host port '.$chosen['host'].' isn’t needed: traffic enters through the edge)' : '').'.'
            : 'Port '.$chosen['port'].' stays private: other services reach it as '.$draft->key.':'.$chosen['port'].'.';
        $draft->note(Note::Translated, $message, $chosen['path'], $chosen['line']);

        foreach ([...$published, ...$routable] as $entry) {
            if ($entry['port'] !== $chosen['port']) {
                $draft->note(Note::Ignored, "Only one port per service gets a domain; {$entry['port']} stays private ({$draft->key}:{$entry['port']}).", $entry['path'], $entry['line']);
            }
        }
    }

    /**
     * @return array{int, int|null, bool}|string container port, host port, bound to loopback; or why not
     */
    private function port(mixed $entry, bool $published): array|string
    {
        $host = null;
        $loopback = false;

        if (is_int($entry)) {
            $container = $entry;
        } elseif (is_string($entry)) {
            try {
                $entry = $this->interpolation->substitute($entry);
            } catch (InterpolationFailed $e) {
                return $e->getMessage();
            }

            if (preg_match('/^(?:(\[[0-9a-fA-F:]+\]|[0-9.]+):)?(?:(\d+(?:-\d+)?)?:)?(\d+(?:-\d+)?)(?:\/(tcp|udp|sctp))?$/D', trim($entry), $m) !== 1) {
                return "“{$entry}” isn’t a port.";
            }

            if (($m[4] ?? '') !== '' && $m[4] !== 'tcp') {
                return strtoupper($m[4]).' ports are refused: launchroom routes HTTP over TCP.';
            }

            if (str_contains($m[3], '-') || str_contains($m[2], '-')) {
                return "The port range {$entry} is refused: give each service one port.";
            }

            $loopback = in_array($m[1], ['127.0.0.1', '[::1]'], true);
            $container = (int) $m[3];
            $host = $m[2] !== '' ? (int) $m[2] : null;
        } elseif (is_array($entry)) {
            $target = $entry['target'] ?? null;
            $protocol = is_string($entry['protocol'] ?? null) ? strtolower($entry['protocol']) : 'tcp';

            if ($protocol !== 'tcp') {
                return strtoupper($protocol).' ports are refused: launchroom routes HTTP over TCP.';
            }

            if (! is_int($target) && (! is_string($target) || ! ctype_digit($target))) {
                return 'The port has no target.';
            }

            $container = (int) $target;
            $host = is_int($entry['published'] ?? null) || (is_string($entry['published'] ?? null) && ctype_digit($entry['published'])) ? (int) $entry['published'] : null;
            $loopback = in_array($entry['host_ip'] ?? null, ['127.0.0.1', '::1'], true);
        } else {
            return 'The port can’t be read.';
        }

        if ($container < 1 || $container > 65535) {
            return "Port {$container} is out of range.";
        }

        return [$container, $published ? $host : null, $loopback];
    }

    private function healthcheck(ServiceDraft $draft): void
    {
        $base = "services.{$draft->composeName}.healthcheck";
        $value = $draft->definition['healthcheck'] ?? null;

        if (! in_array($draft->kind, [ServiceKind::App, ServiceKind::Image], true)) {
            if ($value !== null) {
                $draft->note(Note::Ignored, 'Workers have no port to check; launchroom watches the process.', $base, $this->lines->line($base));
            }

            return;
        }

        if ($value === null) {
            // Built apps learn their port and health path from the build plan.
            if ($draft->port === null && $draft->kind === ServiceKind::Image) {
                $draft->healthcheck = HealthcheckSettings::none();
            } elseif ($draft->databaseImage instanceof DatabaseEngine) {
                $draft->healthcheck = HealthcheckSettings::tcp(startPeriodSeconds: 60);
            }

            return;
        }

        if (! is_array($value)) {
            $draft->note(Note::Refused, 'healthcheck can’t be read.', $base, $this->lines->line($base));

            return;
        }

        $line = $this->lines->line("{$base}.test") ?? $this->lines->line($base);
        $timing = $this->healthTiming($draft, $value, $base);

        if (($value['disable'] ?? false) === true) {
            $draft->healthcheck = HealthcheckSettings::none();
            $draft->note(Note::Translated, 'The health check is off.', $base, $line);

            return;
        }

        $test = $value['test'] ?? null;
        $argv = null;

        try {
            if (is_string($test)) {
                $argv = CommandLine::leadingWords($this->interpolation->substitute($test));
            } elseif (is_array($test)) {
                $words = array_values(array_map(static fn (mixed $word): string => is_scalar($word) ? (string) $word : '', $test));
                $form = strtoupper($words[0] ?? '');

                if ($form === 'NONE') {
                    $draft->healthcheck = HealthcheckSettings::none();
                    $draft->note(Note::Translated, 'The health check is off.', $base, $line);

                    return;
                }

                $argv = match ($form) {
                    'CMD' => array_slice($words, 1),
                    'CMD-SHELL' => CommandLine::leadingWords($this->interpolation->substitute(implode(' ', array_slice($words, 1)))),
                    default => $words,
                };
            }
        } catch (InvalidArgumentException|InterpolationFailed) {
            $argv = null;
        }

        $probe = $argv === null ? null : $this->httpProbe($draft, $argv);

        if ($probe !== null) {
            [$path, $port] = $probe;

            if ($draft->port === null && $port !== null) {
                $draft->port = $port;
            }

            $draft->healthcheck = new HealthcheckSettings(HealthcheckType::Http, $path, '2xx-3xx', ...$timing);
            $draft->note(Note::Translated, "Health check: launchroom requests {$path} from outside.", "{$base}.test", $line);

            return;
        }

        if ($draft->port !== null) {
            $draft->healthcheck = new HealthcheckSettings(HealthcheckType::Tcp, '/', '2xx-3xx', ...$timing);
            $what = $argv === null || $argv === [] ? 'the test' : '`'.mb_strimwidth(implode(' ', $argv), 0, 60, '…').'`';
            $draft->note(Note::Translated, "Health check: launchroom checks port {$draft->port} from outside instead of running {$what}.", "{$base}.test", $line);

            return;
        }

        $draft->healthcheck = HealthcheckSettings::none();
        $draft->note(Note::Ignored, 'The health check runs a command, which launchroom doesn’t do; without a port nothing is checked.', "{$base}.test", $line);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array{startPeriodSeconds: int, intervalSeconds: int, timeoutSeconds: int, failureThreshold: int}
     */
    private function healthTiming(ServiceDraft $draft, array $value, string $base): array
    {
        $defaults = new HealthcheckSettings;
        $read = function (string $field, int $min, int $max, int $default) use ($draft, $value, $base): int {
            if (! array_key_exists($field, $value)) {
                return $default;
            }

            $seconds = ComposeUnits::seconds($value[$field]);

            if ($seconds === null) {
                $draft->note(Note::Ignored, "healthcheck.{$field} can’t be read.", "{$base}.{$field}", $this->lines->line("{$base}.{$field}"));

                return $default;
            }

            return max($min, min($max, $seconds));
        };

        $retries = $value['retries'] ?? null;

        return [
            'startPeriodSeconds' => $read('start_period', 1, 3600, $defaults->startPeriodSeconds),
            'intervalSeconds' => $read('interval', 1, 3600, $defaults->intervalSeconds),
            'timeoutSeconds' => $read('timeout', 1, 600, $defaults->timeoutSeconds),
            'failureThreshold' => is_int($retries) ? max(1, min(100, $retries)) : $defaults->failureThreshold,
        ];
    }

    /**
     * `curl -f http://localhost:3000/health` → [`/health`, 3000].
     *
     * @param  list<string>  $argv
     * @return array{string, int|null}|null
     */
    private function httpProbe(ServiceDraft $draft, array $argv): ?array
    {
        if (! in_array(basename($argv[0] ?? ''), ['curl', 'wget'], true)) {
            return null;
        }

        $hosts = ['localhost', '127.0.0.1', '0.0.0.0', '[::1]', ...$draft->aliases];

        foreach (array_slice($argv, 1) as $word) {
            if (preg_match('#^https?://([^/:?]+|\[[^\]]+\])(?::(\d+))?(/[^\s]*)?$#i', $word, $m) !== 1 || ! in_array(strtolower($m[1]), $hosts, true)) {
                continue;
            }

            $path = ($m[3] ?? '') === '' ? '/' : $m[3];

            if (preg_match(HealthcheckSettings::PATH_PATTERN, $path) !== 1 || strlen($path) > 1024) {
                return null;
            }

            $port = ($m[2] ?? '') === '' ? null : (int) $m[2];

            if ($draft->port !== null && $port !== null && $port !== $draft->port) {
                return null;
            }

            return [$path, $port];
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Variables */
    /* ------------------------------------------------------------------ */

    private function environment(ServiceDraft $draft): void
    {
        $base = "services.{$draft->composeName}";
        /** @var array<string, array{raw: string|null, path: string}> $entries */
        $entries = [];

        foreach ($this->envFiles($draft) as $key => $entry) {
            $entries[$key] = $entry;
        }

        $environment = $draft->definition['environment'] ?? null;

        if (is_array($environment)) {
            foreach ($environment as $key => $value) {
                $path = "{$base}.environment.{$key}";

                if (is_int($key)) {
                    if (! is_string($value)) {
                        continue;
                    }

                    [$name, $raw] = str_contains($value, '=') ? explode('=', $value, 2) : [$value, null];
                    $entries[trim((string) $name)] = ['raw' => $raw, 'path' => $path];

                    continue;
                }

                $entries[$key] = ['raw' => match (true) {
                    $value === null => null,
                    is_bool($value) => $value ? 'true' : 'false',
                    is_scalar($value) => (string) $value,
                    default => '',
                }, 'path' => $path];
            }
        } elseif ($environment !== null) {
            $draft->note(Note::Refused, 'environment can’t be read.', "{$base}.environment", $this->lines->line("{$base}.environment"));
        }

        foreach ($entries as $key => $entry) {
            $line = $this->lines->line($entry['path']);
            $raw = $entry['raw'];

            if (preg_match(self::ENV_KEY, $key) !== 1) {
                $draft->note(Note::Refused, "{$key} isn’t a valid variable name.", $entry['path'], $line);

                continue;
            }

            if ($raw === null) {
                $value = $this->interpolation->value($key);

                if ($value === null) {
                    $draft->note(Note::Warning, "{$key} takes its value from your shell in Compose; it isn’t set here.", $entry['path'], $line);

                    continue;
                }

                $raw = str_replace('$', '$$', $value);
            }

            $draft->rawEnv[$key] = $raw;

            try {
                $resolved = $this->interpolation->substitute($raw);
            } catch (InterpolationFailed) {
                $resolved = '';
            }

            $draft->resolvedEnv[$key] = $resolved;

            if ($draft->kind === ServiceKind::Database) {
                continue;
            }

            try {
                $value = $this->interpolation->forVariable($raw);
            } catch (InterpolationFailed $e) {
                $draft->note(Note::Refused, "{$key}: ".$e->getMessage(), $entry['path'], $line);

                continue;
            }

            if ($this->literalReference($raw)) {
                $draft->note(Note::Refused, "{$key} contains “\${{”, which launchroom reads as a reference.", $entry['path'], $line);

                continue;
            }

            $secret = EnvFileParser::suggestType($key, $resolved) === VariableType::Secret
                || (str_contains(strtoupper($key), 'SECRET') && ! VariableExposure::isBrowserInlined($key));
            $draft->env[$key] = ['raw' => $raw, 'value' => $value, 'resolved' => $resolved, 'secret' => $secret, 'line' => $line];
        }

        if ($draft->kind !== ServiceKind::Database && $draft->env !== []) {
            $secrets = count(array_filter($draft->env, static fn (array $entry): bool => $entry['secret']));
            $draft->note(
                Note::Translated,
                count($draft->env).' '.(count($draft->env) === 1 ? 'variable' : 'variables').($secrets > 0 ? ", {$secrets} stored as ".($secrets === 1 ? 'a secret' : 'secrets') : '').'.',
                "{$base}.environment",
                $this->lines->line("{$base}.environment") ?? $this->lines->line("{$base}.env_file"),
            );
        }
    }

    /**
     * Whether the text (not an interpolation) contains `${{`.
     */
    private function literalReference(string $raw): bool
    {
        try {
            $text = '';

            foreach (ComposeInterpolation::parse($raw) as $token) {
                $text .= $token['type'] === 'text' ? $token['text'] : "\0";
            }

            return str_contains($text, '${{');
        } catch (InterpolationFailed) {
            return false;
        }
    }

    /**
     * @return array<string, array{raw: string|null, path: string}>
     */
    private function envFiles(ServiceDraft $draft): array
    {
        $base = "services.{$draft->composeName}.env_file";
        $value = $draft->definition['env_file'] ?? null;
        $files = match (true) {
            is_string($value) => [$value],
            is_array($value) => array_values($value),
            default => [],
        };
        $entries = [];

        foreach ($files as $index => $file) {
            $required = true;

            if (is_array($file)) {
                $required = ($file['required'] ?? true) !== false;
                $file = $file['path'] ?? null;
            }

            if (! is_string($file)) {
                continue;
            }

            $path = is_string($value) ? $base : "{$base}.{$index}";
            $line = $this->lines->line($path);
            $relative = $this->relativePath($file);
            $content = $relative === null ? null : ($this->options->files[$relative] ?? null);

            if ($content === null) {
                if ($required && $relative !== null) {
                    $this->missingFiles[] = $relative;
                    $draft->note(Note::Warning, "{$file} isn’t here yet: paste it to import its variables.", $path, $line);
                } elseif ($relative === null) {
                    $draft->note(Note::Refused, "{$file} is outside the project.", $path, $line);
                }

                continue;
            }

            $parsed = EnvFileParser::parse($content);

            if ($parsed->errors !== []) {
                $draft->note(Note::Refused, "{$file} has errors and isn’t imported.", $path, $line);

                continue;
            }

            foreach ($parsed->entries as $entry) {
                // env_file values are literal: escape `$` for interpolation.
                $entries[$entry->key] = ['raw' => str_replace('$', '$$', $entry->value), 'path' => $path];
            }

            $draft->note(Note::Translated, count($parsed->entries).' '.(count($parsed->entries) === 1 ? 'variable' : 'variables')." from {$file}.", $path, $line);
        }

        return $entries;
    }

    /**
     * `db_1:5432` → `db-1:5432`: host names of other services become their slug.
     */
    private function rewriteHosts(ServiceDraft $draft): void
    {
        foreach ($draft->env as $key => $entry) {
            $value = $entry['value'];

            foreach ($this->hosts as $alias => $target) {
                if ($alias === $target || ($this->serviceByKey($target)?->kind === ServiceKind::Database)) {
                    continue;
                }

                $quoted = preg_quote($alias, '/');
                $value = (string) preg_replace(
                    ["/^{$quoted}$/iD", "/(?<=\\/\\/|@){$quoted}(?=[:\\/?]|$)/i", "/(?<![A-Za-z0-9_.-]){$quoted}(?=:\\d)/i"],
                    $target,
                    $value,
                );
            }

            $draft->env[$key]['value'] = $value;
        }
    }

    private function serviceByKey(string $key): ?ServiceDraft
    {
        foreach ($this->drafts as $draft) {
            if ($draft->key === $key) {
                return $draft;
            }
        }

        return null;
    }

    /**
     * Consumers of a managed database that repeated its credentials or
     * pointed at its host get references to the generated ones instead.
     */
    private function convertCredentials(): void
    {
        // Templates reference databases explicitly (`${service.db.url}`).
        if ($this->options->template) {
            return;
        }

        foreach ($this->drafts as $database) {
            if ($database->kind !== ServiceKind::Database || $database->database === null) {
                continue;
            }

            $engine = $database->database->engine === DatabaseEngine::Valkey ? 'redis' : $database->database->engine->value;
            $credentials = $this->credentials($database, $engine);
            $slug = $database->key;

            foreach ($this->drafts as $consumer) {
                if ($consumer === $database || $consumer->kind === ServiceKind::Database) {
                    continue;
                }

                foreach ($consumer->env as $key => $entry) {
                    $rewritten = $this->databaseUrl($entry['raw'], $entry['resolved'], $database, $engine)
                        ?? $this->credentialReference($key, $entry, $credentials, $slug)
                        ?? (preg_match('/HOST|SERVER|ADDR/i', $key) === 1 ? $this->hostReference($entry['resolved'], $database, $engine) : null);

                    if ($rewritten === null) {
                        continue;
                    }

                    $consumer->env[$key]['value'] = $rewritten;
                    $consumer->connections[$slug] ??= ConnectionStyle::Url;
                    $consumer->note(Note::Translated, "{$key} now uses {$database->name}’s generated credentials.", "services.{$consumer->composeName}.environment.{$key}", $entry['line']);
                }
            }
        }
    }

    /**
     * @return list<array{raw: string, resolved: string, export: string, role: string}>
     */
    private function credentials(ServiceDraft $database, string $engine): array
    {
        $credentials = [];

        foreach (self::CREDENTIALS[$engine] ?? [] as $role => [$names, $export]) {
            foreach ($names as $name) {
                if (($database->rawEnv[$name] ?? '') !== '') {
                    $credentials[] = ['raw' => $database->rawEnv[$name], 'resolved' => $database->resolvedEnv[$name] ?? '', 'export' => $export, 'role' => $role];
                }
            }
        }

        // Redis passwords usually live in the command.
        $words = $database->rawCommand ?? [];

        foreach ($words as $index => $word) {
            $password = match (true) {
                $word === '--requirepass' => $words[$index + 1] ?? null,
                str_starts_with($word, '--requirepass=') => substr($word, strlen('--requirepass=')),
                default => null,
            };

            if (is_string($password) && $password !== '') {
                try {
                    $resolved = $this->interpolation->substitute($password);
                } catch (InterpolationFailed) {
                    $resolved = '';
                }

                $credentials[] = ['raw' => $password, 'resolved' => $resolved, 'export' => 'REDIS_PASSWORD', 'role' => 'password'];
            }
        }

        return $credentials;
    }

    private function databaseUrl(string $raw, string $resolved, ServiceDraft $database, string $engine): ?string
    {
        foreach ([$raw, $resolved] as $candidate) {
            if (preg_match('~^([a-z][a-z0-9+.-]*)://(?:[^@/]*@)?([^:/?#@]+)(?::[^/?#]*)?(/[^?#]*)?(\?[^#]*)?$~iD', $candidate, $m) !== 1
                || ! in_array(strtolower($m[2]), $database->aliases, true)) {
                continue;
            }

            $slug = $database->key;
            $scheme = $m[1];
            $path = $m[3] ?? '';
            $query = $m[4] ?? '';

            if ($engine === 'redis') {
                return "{$scheme}://default:\${{{$slug}.REDIS_PASSWORD}}@\${{{$slug}.REDIS_HOST}}:\${{{$slug}.REDIS_PORT}}".(preg_match('#^/\d+$#', $path) === 1 ? $path : '').$query;
            }

            $port = self::PORT_EXPORT[$engine];

            if ($engine === 'mongodb' && preg_match('/(?:^\?|&)authSource=/', $query) !== 1) {
                // The generated user lives in the admin database.
                $query = ($query === '' ? '?' : $query.'&').'authSource=admin';
            } elseif ($engine === 'clickhouse' && preg_match('~^(?:clickhouse|tcp)$~i', $scheme) === 1) {
                // The native protocol, not the HTTP interface.
                $port = 'CLICKHOUSE_NATIVE_PORT';
            }

            return "{$scheme}://\${{{$slug}.".self::USER_EXPORT[$engine].'}}:${{'.$slug.'.'.self::PASSWORD_EXPORT[$engine].'}}@${{'.$slug.'.'.self::HOST_EXPORT[$engine].'}}:${{'.$slug.'.'.$port.'}}/${{'.$slug.'.'.self::DATABASE_EXPORT[$engine].'}}'.$query;
        }

        return null;
    }

    /**
     * @param  array{raw: string, value: string, resolved: string, secret: bool, line: int|null}  $entry
     * @param  list<array{raw: string, resolved: string, export: string, role: string}>  $credentials
     */
    private function credentialReference(string $key, array $entry, array $credentials, string $slug): ?string
    {
        foreach ($credentials as $credential) {
            $same = $entry['raw'] === $credential['raw']
                || ($credential['resolved'] !== '' && $entry['resolved'] === $credential['resolved'] && match ($credential['role']) {
                    'user' => preg_match('/USER|LOGIN/i', $key) === 1,
                    'database' => preg_match('/DB|DATABASE|NAME|SCHEMA/i', $key) === 1,
                    default => true,
                });

            if ($same) {
                return "\${{{$slug}.{$credential['export']}}}";
            }
        }

        return null;
    }

    private function hostReference(string $resolved, ServiceDraft $database, string $engine): ?string
    {
        $value = strtolower($resolved);
        $slug = $database->key;

        foreach ($database->aliases as $alias) {
            if ($value === $alias) {
                return "\${{{$slug}.".self::HOST_EXPORT[$engine].'}}';
            }

            if (preg_match('/^'.preg_quote($alias, '/').':\d+$/D', $value) === 1) {
                return "\${{{$slug}.".self::HOST_EXPORT[$engine]."}}:\${{{$slug}.".self::PORT_EXPORT[$engine].'}}';
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Commands, volumes, connections, resources */
    /* ------------------------------------------------------------------ */

    /**
     * @return list<string>|null
     */
    private function argv(ServiceDraft $draft, string $field): ?array
    {
        $path = "services.{$draft->composeName}.{$field}";
        $line = $this->lines->line($path);
        $value = $draft->definition[$field] ?? null;

        try {
            if (is_string($value)) {
                $argv = CommandLine::split($this->interpolation->substitute($value));
            } elseif (is_array($value)) {
                $argv = [];

                foreach ($value as $word) {
                    if (! is_scalar($word) || is_bool($word)) {
                        throw new InvalidArgumentException('Every item must be text.');
                    }

                    $argv[] = $this->interpolation->substitute((string) $word);
                }
            } elseif ($value === null) {
                return null;
            } else {
                throw new InvalidArgumentException('It can’t be read.');
            }
        } catch (InvalidArgumentException|InterpolationFailed $e) {
            $draft->note(Note::Refused, "{$field} is refused: ".$e->getMessage(), $path, $line);

            return null;
        }

        if ($argv === []) {
            return null;
        }

        if (count($argv) > 256 || array_any($argv, static fn (string $word): bool => strlen($word) > 4096 || str_contains($word, "\0"))) {
            $draft->note(Note::Refused, "{$field} is too long.", $path, $line);

            return null;
        }

        $draft->note(Note::Translated, ($field === 'command' ? 'Start command' : 'Entrypoint').': '.mb_strimwidth(implode(' ', $argv), 0, 80, '…').' (run directly, without a shell).', $path, $line);

        return $argv;
    }

    private function volumes(ServiceDraft $draft): void
    {
        $base = "services.{$draft->composeName}.volumes";
        $entries = $draft->definition['volumes'] ?? null;

        if (! is_array($entries)) {
            return;
        }

        foreach (array_values($entries) as $index => $entry) {
            $path = "{$base}.{$index}";
            $line = $this->lines->line($path);
            $mount = $this->mount($entry);

            if (is_string($mount)) {
                $draft->note(Note::Refused, $mount, $path, $line);

                continue;
            }

            ['type' => $type, 'source' => $source, 'target' => $target, 'readOnly' => $readOnly] = $mount;

            if ($type === 'tmpfs') {
                $draft->note(Note::Ignored, "tmpfs at {$target} is ignored.", $path, $line);

                continue;
            }

            if ($type === 'bind' && (str_contains((string) $source, 'docker.sock') || str_contains((string) $source, '/var/run/docker') || str_contains($target, 'docker.sock'))) {
                $draft->note(Note::Refused, 'Mounting the Docker socket is refused: services never control Docker.', $path, $line);

                continue;
            }

            if (preg_match(self::MOUNT_PATH, $target) !== 1 || strlen($target) > 255
                || array_any(explode('/', $target), static fn (string $segment): bool => $segment === '.' || $segment === '..')
                || array_any(['/proc', '/sys', '/dev', '/var/run/docker.sock', '/run/docker.sock'], static fn (string $forbidden): bool => $target === $forbidden || str_starts_with($target, $forbidden.'/'))) {
                $draft->note(Note::Refused, "The mount path {$target} can’t be a volume.", $path, $line);

                continue;
            }

            if ($draft->kind === ServiceKind::Worker) {
                $draft->note(Note::Refused, "Workers have no volumes; {$target} isn’t mounted.", $path, $line);

                continue;
            }

            if (in_array($target, $draft->volumes, true)) {
                continue;
            }

            if ($type === 'bind') {
                if ($this->looksLikeFile((string) $source, $target)) {
                    $draft->note(Note::Refused, "The file {$source} can’t be mounted from a server: build it into the image or set it with a variable.", $path, $line);

                    continue;
                }

                if (! str_starts_with((string) $source, '.')) {
                    $draft->note(Note::Refused, "{$source} is a folder on the server: services never see the server’s files. Add a volume at {$target} after the import if it needs to keep data.", $path, $line);

                    continue;
                }

                $draft->bindMounts++;

                if (! $this->options->convertBindMounts) {
                    $draft->note(Note::Refused, "The bind mount {$source} is refused: services never see the server’s files. Turn on “Convert to volumes” to start with an empty volume at {$target}.", $path, $line);

                    continue;
                }

                $draft->volumes[] = $target;
                $draft->note(Note::Translated, "The folder {$source} becomes a volume at {$target} (it starts empty).", $path, $line);

                continue;
            }

            if ($source !== null) {
                $owner = $this->volumeOwners[$source] ?? null;

                if ($owner !== null && $owner !== $draft->key) {
                    $draft->note(Note::Refused, "Volume {$source} already belongs to {$owner}: each volume belongs to one service.", $path, $line);

                    continue;
                }

                $this->volumeOwners[$source] = $draft->key;
            }

            $draft->volumes[] = $target;
            $draft->note(Note::Translated, ($source !== null ? "Volume {$source}" : 'A volume').' at '.$target.($readOnly ? ' (mounted read-write)' : '').'.', $path, $line);
        }
    }

    /**
     * @return array{type: string, source: string|null, target: string, readOnly: bool}|string
     */
    private function mount(mixed $entry): array|string
    {
        if (is_string($entry)) {
            try {
                $entry = $this->interpolation->substitute($entry);
            } catch (InterpolationFailed $e) {
                return $e->getMessage();
            }

            $parts = explode(':', $entry);

            if (count($parts) === 1) {
                return ['type' => 'volume', 'source' => null, 'target' => $parts[0], 'readOnly' => false];
            }

            [$source, $target] = $parts;
            $mode = $parts[2] ?? '';
            $bind = str_starts_with($source, '/') || str_starts_with($source, '.') || str_starts_with($source, '~');

            return ['type' => $bind ? 'bind' : 'volume', 'source' => $source, 'target' => $target, 'readOnly' => str_contains($mode, 'ro')];
        }

        if (! is_array($entry)) {
            return 'The volume can’t be read.';
        }

        $type = is_string($entry['type'] ?? null) ? $entry['type'] : 'volume';
        $target = is_string($entry['target'] ?? null) ? $entry['target'] : null;
        $source = is_string($entry['source'] ?? null) && $entry['source'] !== '' ? $entry['source'] : null;

        if ($target === null) {
            return 'The volume has no target.';
        }

        if (! in_array($type, ['volume', 'bind', 'tmpfs'], true)) {
            return "Mounts of type {$type} are refused.";
        }

        return ['type' => $type, 'source' => $source, 'target' => $target, 'readOnly' => ($entry['read_only'] ?? false) === true];
    }

    private function looksLikeFile(string $source, string $target): bool
    {
        $name = basename($source);
        $targetName = basename($target);

        return preg_match('/\.[A-Za-z0-9]{1,8}$/', $name) === 1
            || preg_match('/\.[A-Za-z0-9]{1,8}$/', $targetName) === 1
            || in_array($name, ['localtime', 'timezone', 'hosts', 'resolv.conf', 'passwd', 'group', 'Caddyfile', 'Dockerfile'], true);
    }

    private function dependsOn(ServiceDraft $draft): void
    {
        $value = $draft->definition['depends_on'] ?? null;
        $names = match (true) {
            is_array($value) && array_is_list($value) => array_values(array_filter($value, is_string(...))),
            is_array($value) => array_map(strval(...), array_keys($value)),
            default => [],
        };
        $draft->dependsOn = $names;
    }

    private function connect(ServiceDraft $draft): void
    {
        $base = "services.{$draft->composeName}.depends_on";

        foreach ($draft->dependsOn as $index => $name) {
            $target = $this->drafts[$name] ?? null;
            $path = is_array($draft->definition['depends_on'] ?? null) && ! array_is_list($draft->definition['depends_on']) ? "{$base}.{$name}" : "{$base}.{$index}";
            $line = $this->lines->line($path) ?? $this->lines->line($base);

            if ($target === null || $target === $draft) {
                $draft->note(Note::Ignored, "depends_on {$name}: that service isn’t imported.", $path, $line);

                continue;
            }

            if ($target->kind === ServiceKind::Database || ($target->port !== null && in_array($target->kind, [ServiceKind::App, ServiceKind::Image], true))) {
                $draft->connections[$target->key] ??= ConnectionStyle::Url;
                $draft->note(Note::Translated, $target->kind === ServiceKind::Database
                    ? "Connected to {$target->name} on the canvas (launchroom starts databases first)."
                    : "Connected to {$target->name} on the canvas.", $path, $line);

                continue;
            }

            $draft->note(Note::Ignored, "depends_on {$name}: launchroom starts everything; {$target->name} has no port to connect to.", $path, $line);
        }

        if ($draft->kind === ServiceKind::Database) {
            $draft->connections = [];

            return;
        }

        $laravel = isset($draft->env['DB_CONNECTION']) || (isset($draft->env['DB_HOST']) && isset($draft->env['DB_DATABASE']));

        foreach ($draft->connections as $to => $style) {
            $target = $this->serviceByKey($to);
            $draft->connections[$to] = $draft->connectStyles[$to] ?? ($laravel && $target?->kind === ServiceKind::Database ? ConnectionStyle::Laravel : $style);
        }

        foreach ($draft->connectStyles as $to => $style) {
            $draft->connections[$to] ??= $style;
        }
    }

    private function capabilities(ServiceDraft $draft): void
    {
        $base = "services.{$draft->composeName}.cap_add";
        $value = $draft->definition['cap_add'] ?? null;

        foreach (is_array($value) ? array_values($value) : [] as $index => $capability) {
            $name = strtoupper((string) preg_replace('/^CAP_/i', '', is_string($capability) ? trim($capability) : ''));
            $path = "{$base}.{$index}";

            if (in_array($name, self::CAPABILITIES, true)) {
                $draft->capabilities[] = $name;
                $draft->note(Note::Translated, "Capability {$name} is allowed.", $path, $this->lines->line($path));

                continue;
            }

            $draft->note(Note::Refused, "Capability {$name} is refused: services may only add ".implode(', ', self::CAPABILITIES).'.', $path, $this->lines->line($path));
        }

        $draft->capabilities = array_values(array_unique($draft->capabilities));
    }

    private function resources(ServiceDraft $draft, string $field): void
    {
        $base = "services.{$draft->composeName}";

        if ($field === 'cpus' || $field === 'mem_limit') {
            $deploy = $draft->definition['deploy'] ?? null;

            if (is_array($deploy) && is_array($deploy['resources'] ?? null) && isset($deploy['resources']['limits'])) {
                return;
            }

            $field === 'cpus'
                ? $this->cpus($draft, $draft->definition['cpus'], "{$base}.cpus")
                : $this->memory($draft, $draft->definition['mem_limit'], "{$base}.mem_limit");

            return;
        }

        $deploy = $draft->definition['deploy'] ?? null;

        if (! is_array($deploy)) {
            return;
        }

        foreach ($deploy as $key => $value) {
            $key = (string) $key;
            $path = "{$base}.deploy.{$key}";
            $line = $this->lines->line($path);

            if ($key === 'replicas') {
                $this->replicas($draft, $value, $path);
            } elseif ($key === 'resources') {
                $this->limits($draft, $value, $path);
            } elseif ($key === 'mode') {
                if ($value === 'global') {
                    $draft->note(Note::Ignored, 'mode: global is ignored: set the replicas instead.', $path, $line);
                }
            } else {
                $draft->note(Note::Ignored, "deploy.{$key} is ignored: launchroom places and updates services itself.", $path, $line);
            }
        }
    }

    private function limits(ServiceDraft $draft, mixed $value, string $path): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $setting) {
            $key = (string) $key;

            if ($key !== 'limits') {
                $draft->note(Note::Ignored, "resources.{$key} is ignored: launchroom reserves what the limits say.", "{$path}.{$key}", $this->lines->line("{$path}.{$key}"));

                continue;
            }

            if (is_array($setting)) {
                if (array_key_exists('cpus', $setting)) {
                    $this->cpus($draft, $setting['cpus'], "{$path}.limits.cpus");
                }

                if (array_key_exists('memory', $setting)) {
                    $this->memory($draft, $setting['memory'], "{$path}.limits.memory");
                }
            }
        }
    }

    private function replicas(ServiceDraft $draft, mixed $value, string $path): void
    {
        $line = $this->lines->line($path);

        if (! is_int($value) || $value < 0) {
            $draft->note(Note::Ignored, 'The replica count can’t be read.', $path, $line);

            return;
        }

        $replicas = min(64, $value);
        $draft->scaling->replicas = $replicas;
        $draft->note(Note::Translated, $replicas === 1 ? 'One replica.' : "{$replicas} replicas".($value > 64 ? ' (at most 64).' : '.'), $path, $line);
    }

    private function cpus(ServiceDraft $draft, mixed $value, string $path): void
    {
        $cpus = ComposeUnits::cpus($value);

        if ($cpus === null) {
            $draft->note(Note::Ignored, 'The CPU limit can’t be read.', $path, $this->lines->line($path));

            return;
        }

        $draft->scaling->cpus = max(0.05, min(1024.0, round($cpus, 2)));
        $draft->note(Note::Translated, "CPU limit {$draft->scaling->cpus}.", $path, $this->lines->line($path));
    }

    private function memory(ServiceDraft $draft, mixed $value, string $path): void
    {
        $megabytes = ComposeUnits::megabytes($value);

        if ($megabytes === null) {
            $draft->note(Note::Ignored, 'The memory limit can’t be read.', $path, $this->lines->line($path));

            return;
        }

        $draft->scaling->memoryMb = max(16, min(1048576, $megabytes));
        $draft->note(Note::Translated, "Memory limit {$draft->scaling->memoryMb} MB.", $path, $this->lines->line($path));
    }

    /* ------------------------------------------------------------------ */
    /* Project-level output */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function projectName(array $data): string
    {
        $name = $this->options->name ?? (is_string($data['name'] ?? null) ? $data['name'] : null) ?? 'compose';

        return mb_substr(trim($name) === '' ? 'compose' : trim($name), 0, 100);
    }

    /**
     * Interpolated names used by variables, as project shared variables.
     *
     * @return list<BlueprintVariable>
     */
    private function sharedVariables(): array
    {
        $used = [];

        foreach ($this->drafts as $draft) {
            foreach ($draft->env as $entry) {
                if (preg_match_all('/\$\{\{shared\.([A-Za-z_][A-Za-z0-9_]*)\}\}/', $entry['value'], $m) > 0) {
                    foreach ($m[1] as $name) {
                        $used[$name] = true;
                    }
                }
            }
        }

        $shared = [];

        foreach ($this->interpolation->names() as $name => $info) {
            if (! isset($used[$name])) {
                continue;
            }

            $value = $this->interpolation->value($name);

            if ($value !== null) {
                $shared[] = new BlueprintVariable($name, $value, EnvFileParser::suggestType($name, $value) === VariableType::Secret);
            } elseif ($info['generated']) {
                $shared[] = new BlueprintVariable($name, '${{ secret(32) }}', true);
            } else {
                $shared[] = new BlueprintVariable($name, '', false);
                $this->projectNote(Note::Warning, $info['required']
                    ? "{$name} is required".($info['message'] !== null ? " ({$info['message']})" : '').': set it before the first deploy works.'
                    : "{$name} has no value yet: set it in the project’s shared variables.", $name, null);
            }
        }

        return $shared;
    }

    /**
     * @return list<ComposeInputData>
     */
    private function inputs(ProjectBlueprint $blueprint): array
    {
        $inputs = [];
        $shared = array_map(static fn (BlueprintVariable $variable): string => $variable->key, $blueprint->sharedVariables);

        foreach ($this->interpolation->names() as $name => $info) {
            // Only names that still matter: shared variables, and values nobody gave that were required.
            if (! in_array($name, $shared, true) && (! $info['required'] || $info['provided'])) {
                continue;
            }

            $status = match (true) {
                $info['provided'] => 'provided',
                $info['generated'] => 'generated',
                default => 'missing',
            };
            $inputs[] = new ComposeInputData($name, $status, $info['required'], ComposeInterpolation::looksSecret($name), $info['message']);
        }

        return $inputs;
    }

    private function projectNote(Note $kind, string $message, string $field, ?int $line): null
    {
        $this->notes[] = new BlueprintNote($kind, $message, $field, $line);

        return null;
    }
}
