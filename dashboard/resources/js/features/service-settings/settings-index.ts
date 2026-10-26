import { buildSettingsIndex } from '@/components/settings/search';
import type { SettingDraft, SettingResult } from '@/components/settings/search';
import type { SectionId, SettingsArea } from './sections';
import type { SettingsContext } from './settings-context';

/** Where a result leads: a settings page. */
export type SettingTarget = SectionId;

/**
 * Every setting of the Settings tab for this service (docs/08 §16), in
 * sidebar order, filtered by kind and permissions the same way
 * `settingsAreas` builds the sidebar. The only list: add a row here when a
 * page gains one (`anchor` is its `setting` prop).
 */
export function settingsIndex(
    context: SettingsContext,
    areas: SettingsArea[],
): SettingResult<SettingTarget>[] {
    const { node, settings, canUpdate, canDelete } = context;
    const kind = node.kind;
    const web = kind === 'app' || kind === 'image';
    const built = kind === 'app' || kind === 'worker' || kind === 'cron';
    const stateful =
        settings.placement?.stateful ??
        (kind === 'database' || settings.volumes.length > 0);
    const git = settings.source.type === 'git' && !settings.source.parentName;
    const hasPresets = (settings.detected?.presets ?? []).some(
        (preset) => preset.decisions.length > 0,
    );

    const drafts: SettingDraft<SettingTarget>[] = [
        // General
        {
            target: 'general',
            label: 'Name',
            hint: 'Rename the service',
            keywords: ['rename', 'title', 'call'],
            anchor: 'name',
        },
        {
            target: 'general',
            label: 'Slug',
            hint: 'Private hostname, fixed once created',
            keywords: ['identifier', 'handle', 'hostname'],
            anchor: 'slug',
        },

        // Source
        {
            target: 'source',
            label:
                settings.source.type === 'image'
                    ? 'Image'
                    : settings.source.type === 'database'
                      ? 'Engine'
                      : 'Repository',
            hint:
                settings.source.type === 'image'
                    ? 'The published image and its tag'
                    : settings.source.type === 'database'
                      ? 'The database engine'
                      : 'Where the code comes from',
            keywords: [
                'source',
                'repository',
                'repo',
                'github',
                'git',
                'image',
                'docker image',
                'tag',
                'registry',
                'engine',
                'change image',
            ],
            anchor:
                settings.source.type === 'image' && canUpdate
                    ? 'image'
                    : undefined,
        },
        {
            target: 'source',
            label: 'Branch',
            hint: 'Which branch deploys here',
            keywords: ['git branch', 'deploys from', 'override branch', 'main'],
            anchor: 'branch',
            when: git,
        },
        {
            target: 'source',
            label: 'Root directory',
            hint: 'Where the app lives in the repository',
            keywords: ['monorepo', 'folder', 'subdirectory', 'path'],
            anchor: 'root-directory',
            when: git,
        },
        {
            target: 'source',
            label: 'Versions',
            hint: 'Engine versions launchroom runs',
            keywords: ['version', 'upgrade', 'postgres', 'mysql', 'redis'],
            anchor: 'versions',
            when: settings.source.type === 'database',
        },

        // Variables
        {
            target: 'variables',
            label: 'Variables',
            hint: 'Environment variables and secrets',
            keywords: [
                'variable',
                'env',
                'environment variables',
                'secret',
                '.env',
                'config',
                'keys',
            ],
            anchor: 'variables-list',
        },
        {
            target: 'variables',
            label: 'Add variable',
            hint: 'A key and its value for this environment',
            keywords: ['new', 'env', 'secret', 'create'],
            anchor: 'add-variable',
            fallback: 'variables-list',
            when: canUpdate,
        },
        {
            target: 'variables',
            label: 'Import .env',
            hint: 'Paste or upload a .env file',
            keywords: ['dotenv', 'paste', 'upload', 'bulk', 'env file'],
            anchor: 'import-env',
            fallback: 'variables-list',
            when: canUpdate,
        },
        {
            target: 'variables',
            label: 'Raw .env',
            hint: 'Edit every variable as text',
            keywords: ['text', 'editor', 'bulk', 'dotenv'],
            anchor: 'raw-env',
            fallback: 'variables-list',
            when: canUpdate,
        },
        {
            target: 'variables',
            label: 'Shared variables',
            hint: 'Project-wide values',
            keywords: ['shared', 'project', 'global', 'env'],
            anchor: 'shared-variables',
        },
        {
            target: 'variables',
            label: 'System variables',
            hint: 'Set by launchroom on every deployment',
            keywords: ['launchroom', 'builtin', 'port', 'hostname', 'env'],
            anchor: 'system-variables',
        },

        // Build
        {
            target: 'build',
            label: 'Builder',
            hint: 'Railpack or your Dockerfile',
            keywords: ['railpack', 'dockerfile', 'docker', 'buildpack'],
            anchor: 'builder',
        },
        {
            target: 'build',
            label: 'Dockerfile',
            hint: 'Build with your own Dockerfile',
            keywords: ['dockerfile path', 'docker', 'containerfile'],
            anchor: 'dockerfile',
            fallback: 'builder',
        },
        {
            target: 'build',
            label: 'Install command',
            hint: 'How dependencies are installed',
            keywords: [
                'install',
                'dependencies',
                'npm ci',
                'composer',
                'pnpm',
                'yarn',
            ],
            anchor: 'install-command',
        },
        {
            target: 'build',
            label: 'Build command',
            hint: 'What runs to build your app',
            keywords: ['compile', 'npm run build', 'bundle'],
            anchor: 'build-command',
        },
        {
            target: 'build',
            label: 'Preset decisions',
            hint: 'What the framework preset changed',
            keywords: ['preset', 'framework', 'detected'],
            anchor: 'preset-decisions',
            when: hasPresets,
        },
        {
            target: 'build',
            label: 'Watch paths',
            hint: 'Only these paths trigger a deploy',
            keywords: ['monorepo', 'paths', 'ignore', 'trigger', 'glob'],
            anchor: 'watch-paths',
        },
        {
            target: 'build',
            label: 'Railpack version',
            hint: 'Pin the builder version',
            keywords: ['builder version', 'pin'],
            anchor: 'railpack-version',
        },
        {
            target: 'build',
            label: 'railpack.json',
            hint: 'Keep the build settings with your code',
            keywords: ['export', 'download', 'config file'],
            anchor: 'railpack-json',
            when: settings.build.builder !== 'dockerfile',
        },

        // Deploy
        {
            target: 'deploy',
            label: built ? 'Start command' : 'Command',
            hint:
                kind === 'cron'
                    ? 'What the job runs'
                    : 'How the service starts',
            keywords: [
                'command',
                'start',
                'run',
                'entrypoint',
                'cmd',
                ...(kind === 'cron' ? ['job'] : []),
                ...(kind === 'worker' ? ['queue', 'worker'] : []),
            ],
            anchor: 'start-command',
        },
        {
            target: 'deploy',
            label: 'Port',
            hint: 'Where it listens for requests',
            keywords: ['listen', 'http port', 'expose'],
            anchor: 'port',
            when: web,
        },
        {
            target: 'deploy',
            label: 'Release command',
            hint: 'Runs once per deploy, such as migrations',
            keywords: ['migrations', 'migrate', 'pre-deploy', 'hook'],
            anchor: 'release-command',
            when: built,
        },
        {
            target: 'deploy',
            label: 'Health check',
            hint: 'How launchroom knows it is ready',
            keywords: [
                'healthcheck',
                'health',
                'readiness',
                'liveness',
                'probe',
                'http',
                'tcp',
            ],
            anchor: 'health-check',
        },
        {
            target: 'deploy',
            label: 'Health check path',
            hint: 'The URL the check asks',
            keywords: ['healthcheck path', '/health', 'endpoint'],
            anchor: 'health-path',
            fallback: 'health-check',
        },
        {
            target: 'deploy',
            label: 'Health check timing',
            hint: 'Start time, interval, timeout, retries',
            keywords: [
                'healthcheck',
                'interval',
                'timeout',
                'start period',
                'grace',
                'retries',
                'failures',
            ],
            anchor: 'health-timing',
            fallback: 'health-check',
        },
        {
            target: 'deploy',
            label: 'Drain time',
            hint: 'How long old replicas keep answering',
            keywords: [
                'rollout',
                'graceful',
                'shutdown',
                'zero downtime',
                'draining',
            ],
            anchor: 'drain',
        },
        {
            target: 'deploy',
            label: 'Start timeout',
            hint: 'How long a new version may take to start',
            keywords: ['timeout', 'rollout', 'pull', 'download', 'stuck'],
            anchor: 'rollout-timeout',
        },
        {
            target: 'deploy',
            label: 'Health check timeout',
            hint: 'How long a new container may take to become healthy',
            keywords: ['timeout', 'health', 'ready', 'slow start'],
            anchor: 'health-timeout',
        },

        // Schedule (cron services)
        {
            target: 'schedule',
            label: 'Schedule',
            hint: 'When the job runs',
            keywords: [
                'cron',
                'schedule',
                'crontab',
                'interval',
                'every',
                'hourly',
                'daily',
                'nightly',
                'weekly',
                'timer',
                'job',
            ],
            anchor: 'schedule',
            fallback: 'schedule-mode',
        },
        {
            target: 'schedule',
            label: 'Cron expression',
            hint: 'Minute, hour, day, month, weekday',
            keywords: ['cron', 'expression', 'crontab', '* * * * *'],
            anchor: 'schedule-cron',
            fallback: 'schedule-mode',
        },
        {
            target: 'schedule',
            label: 'Time zone',
            hint: 'The clock the schedule follows',
            keywords: [
                'timezone',
                'tz',
                'utc',
                'daylight saving',
                'dst',
                'cron',
            ],
            anchor: 'schedule-time-zone',
            fallback: 'schedule-mode',
        },
        {
            target: 'schedule',
            label: 'Next runs',
            hint: 'When it runs next',
            keywords: ['upcoming', 'next', 'when', 'cron'],
            anchor: 'schedule-next-runs',
            fallback: 'schedule-mode',
        },

        // Domains
        {
            target: 'domains',
            label: 'Add domain',
            hint: 'Put it on your own domain',
            keywords: [
                'domain',
                'custom domain',
                'hostname',
                'url',
                'dns',
                'a record',
                'cname',
            ],
            anchor: 'add-domain',
            fallback: 'domains-list',
        },
        {
            target: 'domains',
            label: 'HTTPS and certificates',
            hint: 'Issued and renewed automatically',
            keywords: ['ssl', 'tls', 'https', 'certificate', 'cert'],
            anchor: 'domain-https',
            fallback: 'domains-list',
        },
        {
            target: 'domains',
            label: 'Always HTTPS (HSTS)',
            hint: 'Browsers remember to use HTTPS',
            keywords: ['hsts', 'https', 'ssl', 'security'],
            anchor: 'domain-hsts',
            fallback: 'domains-list',
        },
        {
            target: 'domains',
            label: 'Redirect www',
            hint: 'Send www to the bare domain',
            keywords: ['www', 'redirect', 'apex'],
            anchor: 'domain-www',
            fallback: 'domains-list',
        },
        {
            target: 'domains',
            label: 'Edge servers',
            hint: 'The servers that answer for your domains',
            keywords: ['edge', 'traffic', 'proxy', 'load balancer', 'ingress'],
            anchor: 'domain-edges',
            fallback: 'domains-list',
        },

        // Web firewall (docs/22 §9.6)
        {
            target: 'firewall',
            label: 'Web firewall',
            hint: 'Block or log attacks on this service',
            keywords: [
                'waf',
                'firewall',
                'attack',
                'block',
                'security',
                'exception',
                'sql injection',
                'xss',
                'owasp',
            ],
        },

        // Private network
        {
            target: 'networking',
            label: 'Private address',
            hint: 'How your other services reach it',
            keywords: [
                'internal',
                'private',
                'hostname',
                'service discovery',
                'connect',
                'url',
                'network',
            ],
            anchor: 'private-address',
        },

        // Scaling
        {
            target: 'scaling',
            label: 'Replicas',
            hint: 'How many copies run',
            keywords: [
                'replicas',
                'instances',
                'copies',
                'scale',
                'horizontal',
                'count',
            ],
            anchor: 'replicas',
            when: !stateful,
        },
        {
            target: 'scaling',
            label: 'CPU and memory',
            hint: 'Size of each replica',
            keywords: [
                'cpu',
                'memory',
                'ram',
                'cores',
                'limits',
                'resources',
                'size',
            ],
            anchor: 'replica-size',
        },
        {
            target: 'scaling',
            label: 'Distribution',
            hint: 'Spread across servers or pack them',
            keywords: ['spread', 'pack', 'strategy', 'high availability'],
            anchor: 'distribution',
            when: !stateful,
        },
        {
            target: 'scaling',
            label: 'Servers',
            hint: 'Where it runs, per server',
            keywords: ['placement', 'server', 'pin', 'node', 'affinity'],
            anchor: 'placement',
        },
        {
            target: 'scaling',
            label: 'Required labels',
            hint: 'Only servers with these labels',
            keywords: ['labels', 'constraints', 'tags', 'placement'],
            anchor: 'labels',
        },

        // Volumes
        {
            target: 'volumes',
            label: settings.volumes.length > 0 ? 'Volumes' : 'Add volume',
            hint: 'Files that survive deploys',
            keywords: [
                'volume',
                'disk',
                'storage',
                'persistent',
                'mount',
                'data',
                'files',
            ],
            anchor: canUpdate && web ? 'add-volume' : undefined,
            fallback: 'volumes-list',
        },

        // Backups (databases and services with volumes)
        {
            target: 'backups',
            label: 'Backups',
            hint: 'Snapshots and their schedule',
            keywords: [
                'backup',
                'snapshot',
                'schedule',
                'back up now',
                'protection',
            ],
        },
        {
            target: 'backups',
            label: 'Restore',
            hint: 'As a new service or in place',
            keywords: ['restore', 'rollback', 'recover', 'snapshot'],
            anchor: 'restore',
            fallback: 'snapshots',
        },
        {
            target: 'backups',
            label: 'Point-in-time recovery',
            hint: 'Restore to any second',
            keywords: ['pitr', 'wal', 'point in time', 'recovery'],
            when: kind === 'database',
        },
        {
            target: 'backups',
            label: kind === 'database' ? 'Import data' : 'Import files',
            hint: 'From a dump, a connection or an archive',
            keywords: ['import', 'migrate', 'dump', 'upload', 'restore'],
            anchor: 'move-data',
        },
        {
            target: 'backups',
            label: 'Download',
            hint: 'A copy of the data as a file',
            keywords: ['download', 'export', 'dump', 'copy'],
            anchor: 'move-data',
        },

        // Advanced
        {
            target: 'advanced',
            label: 'Entrypoint',
            hint: 'Replace the image’s ENTRYPOINT',
            keywords: [
                'entrypoint',
                'entry point',
                'docker',
                'argv',
                'arguments',
                'compose',
                'command',
            ],
            anchor: 'entrypoint',
            when: kind !== 'database',
        },
        {
            target: 'advanced',
            label: 'Capabilities',
            hint: 'Linux capabilities the container may use',
            keywords: [
                'capabilities',
                'cap_add',
                'cap add',
                'linux',
                'permissions',
                'privileges',
                'root',
                'chown',
                'setuid',
                'setgid',
                'net_bind_service',
                'port 80',
                'low ports',
                'compose',
            ],
            anchor: 'capabilities',
            when: kind !== 'database',
        },
        {
            target: 'advanced',
            label: 'Config files',
            hint: 'Files written into the container before it starts',
            keywords: [
                'config',
                'configs',
                'files',
                'init',
                'sql',
                'compose',
                'template',
            ],
            anchor: 'config-files',
            when: kind !== 'database',
        },
        {
            target: 'advanced',
            label: 'Identifiers',
            hint: 'Service, instance and deployment ids',
            keywords: ['id', 'ids', 'debug', 'instance'],
            anchor: 'identifiers',
        },
        {
            target: 'advanced',
            label: 'Runtime',
            hint: 'Docker network and running image',
            keywords: ['docker', 'network', 'image', 'digest'],
            anchor: 'runtime',
        },
        {
            target: 'advanced',
            label: 'Containers',
            hint: 'What runs right now',
            keywords: ['container', 'docker', 'running', 'ps'],
            anchor: 'containers',
        },

        // Danger zone
        {
            target: 'danger',
            label: settings.enabled ? 'Disable service' : 'Enable service',
            hint: settings.enabled
                ? 'Stop it in this environment'
                : 'Start it again',
            keywords: ['disable', 'enable', 'stop', 'pause', 'turn off'],
            anchor: 'disable',
            when: canUpdate,
        },
        {
            target: 'danger',
            label: 'Remove service',
            hint: 'From this environment',
            keywords: ['delete', 'remove', 'destroy'],
            anchor: 'remove',
            when: canDelete,
        },
    ];

    return buildSettingsIndex<SettingTarget>(drafts, areas);
}
