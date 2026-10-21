import type { SectionId, SettingsArea } from './sections';
import { allPages } from './sections';
import type { SettingsContext } from './settings-context';

/** Where a result leads: a settings page, or the panel's Variables tab. */
export type SettingTarget = SectionId | 'variables';

/**
 * One searchable setting (docs/08 §16): its row title, one quiet hint, and
 * the words people type for it. `anchor` is the `data-setting` of the row
 * or card on the page (`SettingsRow`/`SettingsCard`/`OverrideRow`
 * `setting` prop); when that row isn't rendered right now (a Dockerfile
 * row while Railpack builds), `fallback` is tried, then the page's top.
 */
export type SettingEntry = {
    key: string;
    target: SettingTarget;
    label: string;
    hint: string;
    keywords: string[];
    anchor?: string;
    fallback?: string;
};

export type SettingResult = SettingEntry & {
    /** The page (or tab) name the result is grouped under. */
    group: string;
};

type Draft = Omit<SettingEntry, 'key'> & { when?: boolean };

/**
 * Every setting of the Settings tab for this service, in sidebar order,
 * filtered by kind and permissions the same way `settingsAreas` builds
 * the sidebar. The only list: add a row here when a page gains one.
 */
export function settingsIndex(
    context: SettingsContext,
    areas: SettingsArea[],
): SettingResult[] {
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

    const drafts: Draft[] = [
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
            hint: 'Private hostname, never changes',
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
            ],
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
            hint: 'Issued and renewed by themselves',
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
            hint: 'Only servers carrying these labels',
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
                'backup',
            ],
            anchor: canUpdate && web ? 'add-volume' : undefined,
            fallback: 'volumes-list',
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

        // The Variables tab
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
        },
    ];

    const pages = new Map(
        allPages(areas).map((page) => [page.id, page.label] as const),
    );

    return drafts
        .filter((draft) => draft.when !== false)
        .flatMap(({ when: _when, ...entry }) => {
            const group =
                entry.target === 'variables'
                    ? 'Variables tab'
                    : pages.get(entry.target);

            return group === undefined
                ? []
                : [{ ...entry, key: `${entry.target}:${entry.label}`, group }];
        });
}

function normalize(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9./]+/g, ' ')
        .trim();
}

/**
 * Every word of the query must start a word of the label, page name or
 * keywords (prefix match, so "health" finds "Health check" and "env"
 * finds Variables). Ranked: label matches first, then page, then keywords;
 * the index order breaks ties.
 */
export function searchSettings(
    index: SettingResult[],
    query: string,
): SettingResult[] {
    const terms = normalize(query).split(' ').filter(Boolean);

    if (terms.length === 0) {
        return [];
    }

    const score = (entry: SettingResult): number => {
        const label = normalize(entry.label);
        const group = normalize(entry.group);
        const keywords = entry.keywords.map(normalize).join(' ');
        const words = (text: string) => text.split(' ');
        const hit = (text: string, term: string) =>
            words(text).some((word) => word.startsWith(term)) ||
            (term.length > 2 && text.includes(term));
        let total = 0;

        for (const term of terms) {
            if (hit(label, term)) {
                total += label.startsWith(term) ? 4 : 3;
            } else if (hit(group, term)) {
                total += 2;
            } else if (hit(keywords, term)) {
                total += 1;
            } else {
                return 0;
            }
        }

        return total;
    };

    return index
        .map((entry, order) => ({ entry, order, score: score(entry) }))
        .filter((item) => item.score > 0)
        .sort((a, b) => b.score - a.score || a.order - b.order)
        .map((item) => item.entry);
}

/** Results grouped by page, groups in the order of their best result. */
export function groupResults(
    results: SettingResult[],
): { group: string; items: SettingResult[] }[] {
    const groups = new Map<string, SettingResult[]>();

    results.forEach((result) => {
        groups.set(result.group, [...(groups.get(result.group) ?? []), result]);
    });

    return Array.from(groups, ([group, items]) => ({ group, items }));
}
