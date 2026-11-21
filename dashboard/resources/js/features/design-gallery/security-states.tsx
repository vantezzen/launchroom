import { FixGroupCard, FixGroupList } from '@/features/security/action-needed';

/**
 * "Action needed" grouped by what to fix (docs/22 §9.2 part 2, D107) with
 * sample data, for review and screenshots: a known exploited package in
 * production with a concerning investigation (open), one advisory in one
 * service, no fixed version yet, one only outside production, and more
 * groups than the list shows. Buttons do nothing.
 */

type FixGroup = App.Data.Security.FixGroupData;
type FixService = App.Data.Security.FixGroupServiceData;

const at = '2026-11-20T08:12:00Z';

function service(
    name: string,
    project: string,
    environment = 'production',
    over: Partial<FixService> = {},
): FixService {
    return {
        service: {
            id: `svc_${name}_${project}`,
            name,
            logo: name === 'web' ? 'nextjs' : 'node',
            kind: 'web',
            projectId: `prj_${project}`,
            projectName: project,
            environmentId: `env_${project}_${environment}`,
            environmentName: environment,
            href: '#',
        },
        production: environment === 'production',
        version: '14.1.0',
        fixedVersion: '15.5.24',
        severity: 'critical',
        knownExploited: false,
        findings: [`fnd_${name}_${project}_${environment}`],
        lead: `fnd_${name}_${project}_${environment}`,
        investigation: {
            id: `ivg_${name}`,
            status: 'partial',
            startedAt: at,
            finishedAt: at,
            summary: ['The container couldn’t be checked.'],
        },
        ...over,
    };
}

const next: FixGroup = {
    key: 'fix_0a1b2c3d4e5f6a7b',
    ecosystem: 'npm',
    package: 'next',
    versions: ['14.1.0', '14.2.30'],
    fixedVersion: '15.5.24',
    severity: 'critical',
    cvss: 9.8,
    knownExploited: true,
    knownExploitedSince: '2026-10-03',
    impact: 'Attackers could run code on the server, get past authorization and more.',
    headline: 'Authorization bypass in Next.js middleware',
    production: true,
    services: [
        service('web', 'shop', 'production', {
            knownExploited: true,
            investigation: {
                id: 'ivg_web',
                status: 'completed',
                startedAt: at,
                finishedAt: at,
                summary: [
                    'Checked the container: 2 concerning findings.',
                    '4,937 requests matched attack rules in the last 7 days.',
                ],
            },
        }),
        service('web', 'blog'),
        service('site', 'docs', 'production', { version: '14.2.30' }),
        service('dashboard', 'analytics'),
        service('web', 'acme-store'),
        service('web', 'shop', 'staging'),
    ],
    advisories: [
        {
            id: 'GHSA-f82v-jwr5-mffw',
            title: 'Authorization bypass in Next.js middleware',
            severity: 'critical',
            cvss: 9.1,
            knownExploited: true,
            fixedVersion: '14.2.25',
            services: 6,
            finding: 'fnd_1',
        },
        {
            id: 'GHSA-2xp9-vwfh-vxw4',
            title: 'Unauthenticated remote code execution in the Image Optimization API',
            severity: 'critical',
            cvss: 9.8,
            knownExploited: false,
            fixedVersion: '15.5.24',
            services: 6,
            finding: 'fnd_2',
        },
        {
            id: 'GHSA-gp8f-8m3g-qvj9',
            title: 'Next.js cache poisoning',
            severity: 'high',
            cvss: 7.5,
            knownExploited: false,
            fixedVersion: '14.2.10',
            services: 3,
            finding: 'fnd_3',
        },
    ],
    lead: 'fnd_web_shop_production',
};

const lodash: FixGroup = {
    key: 'fix_1a1b2c3d4e5f6a7b',
    ecosystem: 'npm',
    package: 'lodash',
    versions: ['4.17.20'],
    fixedVersion: '4.17.21',
    severity: 'high',
    cvss: 7.2,
    knownExploited: false,
    knownExploitedSince: null,
    impact: 'Attackers could run code on the server.',
    headline: 'Command injection in lodash template',
    production: true,
    services: [
        service('api', 'shop', 'production', {
            version: '4.17.20',
            fixedVersion: '4.17.21',
            severity: 'high',
            investigation: {
                id: 'ivg_api',
                status: 'completed',
                startedAt: at,
                finishedAt: at,
                summary: ['Checked the container: nothing unusual.'],
            },
        }),
    ],
    advisories: [
        {
            id: 'GHSA-35jh-r3h4-6jhm',
            title: 'Command injection in lodash template',
            severity: 'high',
            cvss: 7.2,
            knownExploited: false,
            fixedVersion: '4.17.21',
            services: 1,
            finding: 'fnd_4',
        },
    ],
    lead: 'fnd_api_shop_production',
};

const node: FixGroup = {
    ...lodash,
    key: 'fix_2a1b2c3d4e5f6a7b',
    ecosystem: 'binary',
    package: 'node',
    versions: ['20.11.1'],
    fixedVersion: null,
    severity: 'high',
    cvss: 8.2,
    impact: null,
    headline:
        'A flaw in Node.js HTTP/2 handling can cause servers to run out of memory.',
    services: [
        service('worker', 'jobs', 'production', {
            version: '20.11.1',
            fixedVersion: null,
            severity: 'high',
            investigation: null,
        }),
    ],
    advisories: [
        {
            ...lodash.advisories[0],
            id: 'CVE-2026-56846',
            title: 'HTTP/2 retained header blocks evade maxSessionMemory',
            fixedVersion: null,
        },
    ],
};

const postcss: FixGroup = {
    ...lodash,
    key: 'fix_3a1b2c3d4e5f6a7b',
    package: 'postcss',
    versions: ['8.4.20'],
    fixedVersion: '8.4.31',
    severity: 'high',
    impact: 'Attackers could inject scripts into pages.',
    production: false,
    services: [
        service('web', 'shop', 'staging', {
            version: '8.4.20',
            fixedVersion: '8.4.31',
            severity: 'high',
            investigation: null,
        }),
    ],
};

const noop = () => undefined;
const handlers = {
    onOpen: noop,
    onInvestigate: noop,
    onChanged: noop,
    canManage: true,
};

export default function SecurityStates() {
    return (
        <div className="flex flex-col gap-8">
            <div className="flex flex-col gap-2">
                <p className="type-label text-muted-foreground">
                    A known exploited package in six services, open
                </p>
                <FixGroupCard group={next} defaultOpen {...handlers} />
            </div>
            <div className="flex flex-col gap-2">
                <p className="type-label text-muted-foreground">
                    The list: production first, then outside production, four at
                    a time
                </p>
                <FixGroupList
                    groups={[lodash, node, postcss, postcss, postcss].map(
                        (g, i) => ({ ...g, key: `${g.key}_${i}` }),
                    )}
                    {...handlers}
                />
            </div>
        </div>
    );
}
