import { usePage } from '@inertiajs/react';
import {
    Bell,
    Bot,
    Container,
    FileCode2,
    Fingerprint,
    Gauge,
    GitBranch,
    Globe,
    KeyRound,
    LockKeyhole,
    Network,
    Palette,
    Plug,
    RefreshCw,
    ShieldCheck,
    Truck,
    UserRound,
    Users,
} from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { Page } from '@/components/patterns/page';
import { SettingsPage } from '@/components/settings/settings-page';
import { SettingsShell } from '@/components/settings/settings-shell';
import { allPages } from '@/components/settings/types';
import type { SettingsNavGroup } from '@/components/settings/types';
import AppLayout from '@/features/app-shell/app-layout';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { BreadcrumbItem } from '@/types';
import { accountSettingsIndex } from './settings-index';
import { aiAgents as editAiAgents } from '@/routes';
import { index as editApiTokens } from '@/routes/api-tokens';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editDashboardDomain } from '@/routes/dashboard-domain';
import { edit as editDockerfileGeneration } from '@/routes/dockerfile-generation';
import { edit as editGit } from '@/routes/git';
import { edit as editInstanceSecurity } from '@/routes/instance-security';
import { edit as editMove } from '@/routes/move';
import { edit as editNetwork } from '@/routes/network-settings';
import { edit as editNotifications } from '@/routes/notifications';
import { edit as editProfile } from '@/routes/profile';
import { index as editRegistryCredentials } from '@/routes/registry-credentials';
import { edit as editRegistry } from '@/routes/registry-settings';
import { index as editRobotTokens } from '@/routes/robot-tokens';
import { edit as editSecurity } from '@/routes/security';
import { index as settingsIndex } from '@/routes/settings';
import { show as showSystem } from '@/routes/system';
import { edit as editTeam } from '@/routes/team';
import { edit as editUpdates } from '@/routes/updates';

/** Each page's route; the keys are every settings page. */
const routes = {
    profile: editProfile,
    security: editSecurity,
    'api-tokens': editApiTokens,
    'ai-agents': editAiAgents,
    appearance: editAppearance,
    team: editTeam,
    'robot-tokens': editRobotTokens,
    git: editGit,
    registries: editRegistryCredentials,
    notifications: editNotifications,
    'dockerfile-generation': editDockerfileGeneration,
    system: showSystem,
    updates: editUpdates,
    network: editNetwork,
    'dashboard-domain': editDashboardDomain,
    registry: editRegistry,
    'instance-security': editInstanceSecurity,
    move: editMove,
} satisfies Record<string, () => { url: string }>;

export type AccountPage = keyof typeof routes;

/** One sentence under each page title; Move launchroom opens with its own. */
const descriptions: Partial<Record<AccountPage, string>> = {
    profile: 'Your name and the email you sign in with.',
    security: 'How you sign in: two-factor, passkeys and your password.',
    'api-tokens': 'Tokens for the CLI and the API, each bound to one team.',
    'ai-agents':
        'Let the coding agent in your editor deploy and fix things here.',
    appearance: 'How the dashboard looks in this browser.',
    team: 'Who is in this team and what they may do.',
    'robot-tokens': 'Team tokens for CI that don’t belong to one person.',
    git: 'Where your repositories come from.',
    registries: 'Sign-in details for registries that hold your private images.',
    notifications: 'Where launchroom tells you when something needs you.',
    'dockerfile-generation':
        'Suggest a Dockerfile when launchroom can’t detect how to build an app.',
    system: 'How launchroom itself is doing on the main server.',
    updates: 'The version of launchroom and its servers.',
    network: 'Your apps’ domain, the private network and public traffic.',
    'dashboard-domain': 'The address this dashboard answers on.',
    registry: 'Where app images and the build cache are stored.',
    'instance-security':
        'Server certificates, the audit log and vulnerability data sources.',
};

/**
 * Account, team and launchroom settings (`/settings/*`, docs/08 §1): the
 * shared `SettingsShell` with one route per page. Groups: Account · Team ·
 * This launchroom (instance owners; the server authorizes again). `/settings`
 * is Profile beside the sidebar on wide screens and the list on phones.
 * Pages declare it as their layout; the breadcrumbs come from the same list.
 */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl, currentUrl } = useCurrentUrl();
    const { auth, updateAvailable } = usePage().props;
    const owner = auth.user?.isInstanceOwner === true;

    const groups: SettingsNavGroup<AccountPage>[] = [
        {
            id: 'account',
            label: 'Account',
            pages: [
                {
                    id: 'profile',
                    label: 'Profile',
                    icon: UserRound,
                    summary: auth.user?.email,
                },
                {
                    id: 'security',
                    label: 'Sign-in',
                    icon: Fingerprint,
                    summary: 'Two-factor, passkeys and password',
                    keywords: [
                        'security',
                        'password',
                        '2fa',
                        'two-factor',
                        'passkey',
                    ],
                },
                // docs/16 §1.3: personal tokens, each bound to one team.
                {
                    id: 'api-tokens',
                    label: 'API tokens',
                    icon: KeyRound,
                    summary: 'CLI and API access',
                    keywords: ['cli', 'token', 'api'],
                },
                // docs/16 §7: `launchroom mcp` for coding agents.
                {
                    id: 'ai-agents',
                    label: 'AI agents',
                    icon: Plug,
                    summary: 'Connect your editor',
                    keywords: [
                        'mcp',
                        'ai',
                        'agent',
                        'cursor',
                        'claude',
                        'copilot',
                        'editor',
                    ],
                },
                {
                    id: 'appearance',
                    label: 'Appearance',
                    icon: Palette,
                    summary: 'Light, dark or system',
                    keywords: ['theme', 'dark mode', 'light mode'],
                },
            ],
        },
        {
            id: 'team',
            label: 'Team',
            pages: [
                {
                    id: 'team',
                    label: 'Members',
                    icon: Users,
                    summary: 'People and roles',
                    keywords: ['team', 'invite', 'people', 'roles'],
                },
                // docs/16 §1.4: team-owned tokens for CI.
                {
                    id: 'robot-tokens',
                    label: 'Robot tokens',
                    icon: Bot,
                    summary: 'Tokens for CI',
                    keywords: ['robot', 'ci', 'token', 'github actions', 'bot'],
                },
                {
                    id: 'git',
                    label: 'Git',
                    icon: GitBranch,
                    summary: 'Providers and connections',
                    keywords: ['github', 'gitlab', 'forgejo', 'repository'],
                },
                // D91: private images, copied into launchroom's registry on deploy.
                {
                    id: 'registries',
                    label: 'Private registries',
                    icon: LockKeyhole,
                    summary: 'Sign-in details for private images',
                    keywords: [
                        'registry',
                        'private image',
                        'docker hub',
                        'ghcr',
                        'credentials',
                    ],
                },
                {
                    id: 'notifications',
                    label: 'Notifications',
                    icon: Bell,
                    summary: 'Alert channels and rules',
                    keywords: ['alerts', 'email', 'slack', 'webhook'],
                },
                // docs/20: opt-in "Generate a Dockerfile".
                {
                    id: 'dockerfile-generation',
                    label: 'Dockerfile generation',
                    icon: FileCode2,
                    summary: 'Model provider and team switch',
                    keywords: ['ai', 'llm', 'model', 'dockerfile'],
                },
            ],
        },
        // Instance owners only (docs/11 §4); the server authorizes again.
        {
            id: 'instance',
            label: 'This launchroom',
            pages: owner
                ? [
                      // Control-plane health (services, queues, backups, storage).
                      {
                          id: 'system',
                          label: 'System',
                          icon: Gauge,
                          summary: 'Services, queues and storage',
                          keywords: [
                              'health',
                              'status',
                              'horizon',
                              'queue',
                              'failed jobs',
                              'disk',
                              'database size',
                          ],
                      },
                      {
                          id: 'updates',
                          label: 'Updates',
                          icon: RefreshCw,
                          summary: updateAvailable
                              ? 'Update ready'
                              : 'Versions and automatic updates',
                          dot: updateAvailable ? 'Update ready' : undefined,
                          keywords: ['version', 'upgrade', 'release'],
                      },
                      {
                          id: 'network',
                          label: 'Network',
                          icon: Network,
                          summary: 'Apps domain, private network, traffic',
                          keywords: ['mesh', 'tailscale', 'ingress', 'tls'],
                      },
                      // docs/05 §4.1: the dashboard at its own domain.
                      {
                          id: 'dashboard-domain',
                          label: 'Dashboard domain',
                          icon: Globe,
                          summary: 'Where this dashboard answers',
                          keywords: ['url', 'address', 'https'],
                      },
                      {
                          id: 'registry',
                          label: 'Registry',
                          icon: Container,
                          summary: 'Image storage',
                          keywords: ['images', 'bucket', 's3', 'zot'],
                      },
                      // D50: server certificates and the audit log.
                      {
                          id: 'instance-security',
                          label: 'Certificates & audit',
                          icon: ShieldCheck,
                          summary: 'Server certificates and audit log',
                          keywords: [
                              'security',
                              'mtls',
                              'certificates',
                              'audit',
                              'vulnerability',
                              'osv',
                          ],
                      },
                      // docs/14 §13.4: the dashboard and everything to a new machine.
                      {
                          id: 'move',
                          label: 'Move launchroom',
                          icon: Truck,
                          summary: 'To another machine',
                          keywords: ['migrate', 'new server'],
                      },
                  ]
                : [],
        },
    ];
    const visible = groups.filter((group) => group.pages.length > 0);

    const requested =
        allPages(visible).find((page) =>
            isCurrentOrParentUrl(routes[page.id]().url),
        )?.id ?? null;
    const index = currentUrl === settingsIndex().url;
    const current: AccountPage = requested ?? 'profile';
    const page = allPages(visible).find((entry) => entry.id === current);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Settings', href: settingsIndex() },
        ...(index || !page
            ? []
            : [{ title: page.label, href: routes[current]() }]),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Page size="settings">
                <SettingsShell
                    label="Settings"
                    groups={visible}
                    current={current}
                    requested={index ? null : current}
                    hrefFor={(id) =>
                        id === null ? settingsIndex().url : routes[id]().url
                    }
                    index={accountSettingsIndex(visible)}
                    emptyHint="Try “password”, “token” or “domain”."
                >
                    <SettingsPage
                        title={page?.label ?? 'Settings'}
                        description={descriptions[current]}
                        className="gap-10"
                    >
                        {children}
                    </SettingsPage>
                </SettingsShell>
            </Page>
        </AppLayout>
    );
}
