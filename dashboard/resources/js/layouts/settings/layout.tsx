import { usePage } from '@inertiajs/react';
import {
    Bell,
    Container,
    DatabaseBackup,
    FileCode2,
    GitBranch,
    Globe,
    KeyRound,
    LockKeyhole,
    Network,
    Palette,
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
import { accountSettingsIndex } from '@/features/settings/settings-index';
import type { AccountPage } from '@/features/settings/settings-index';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { index as editApiTokens } from '@/routes/api-tokens';
import { edit as editAppearance } from '@/routes/appearance';
import { settings as editBackups } from '@/routes/backups';
import { edit as editDashboardDomain } from '@/routes/dashboard-domain';
import { edit as editDockerfileGeneration } from '@/routes/dockerfile-generation';
import { edit as editGit } from '@/routes/git';
import { edit as editInstanceSecurity } from '@/routes/instance-security';
import { edit as editMove } from '@/routes/move';
import { edit as editNetwork } from '@/routes/network-settings';
import { edit as editNotifications } from '@/routes/notifications';
import { edit as editProfile } from '@/routes/profile';
import { edit as editRegistry } from '@/routes/registry-settings';
import { edit as editSecurity } from '@/routes/security';
import { index as settingsIndex } from '@/routes/settings';
import { edit as editTeam } from '@/routes/team';
import { edit as editUpdates } from '@/routes/updates';

/** Each page's route. */
const routes: Record<AccountPage, () => { url: string }> = {
    profile: editProfile,
    security: editSecurity,
    'api-tokens': editApiTokens,
    appearance: editAppearance,
    team: editTeam,
    git: editGit,
    backups: editBackups,
    notifications: editNotifications,
    'dockerfile-generation': editDockerfileGeneration,
    updates: editUpdates,
    network: editNetwork,
    'dashboard-domain': editDashboardDomain,
    registry: editRegistry,
    'instance-security': editInstanceSecurity,
    move: editMove,
};

/** One sentence under each page title; Move launchroom opens with its own. */
const descriptions: Partial<Record<AccountPage, string>> = {
    profile: 'Your name and the email you sign in with.',
    security: 'Password, two-factor authentication and passkeys.',
    'api-tokens': 'Tokens for the CLI and the API, each bound to one team.',
    appearance: 'How the dashboard looks in this browser.',
    team: 'Who is in this team and what they may do.',
    git: 'Where your repositories come from.',
    backups: 'Where backups go and what they protect.',
    notifications: 'Where launchroom tells you when something needs you.',
    'dockerfile-generation':
        'Suggest a Dockerfile when launchroom can’t detect how to build an app.',
    updates: 'The version of launchroom and its servers.',
    network: 'Your apps’ domain, the private network and public traffic.',
    'dashboard-domain': 'The address this dashboard answers on.',
    registry: 'Where app images and the build cache are stored.',
    'instance-security': 'Server certificates and the audit log.',
};

/**
 * Account, team and launchroom settings (`/settings/*`, docs/08 §1): the
 * shared `SettingsShell` with one route per page. Groups: Account · Team ·
 * This launchroom (instance owners; the server authorizes again). `/settings`
 * is Profile beside the sidebar on wide screens and the list on phones.
 */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl, currentUrl } = useCurrentUrl();
    const { auth, updateAvailable } = usePage().props;
    const owner = auth.user.is_instance_owner === true;

    const groups: SettingsNavGroup<AccountPage>[] = [
        {
            id: 'account',
            label: 'Account',
            pages: [
                {
                    id: 'profile',
                    label: 'Profile',
                    icon: UserRound,
                    summary: auth.user.email,
                },
                {
                    id: 'security',
                    label: 'Security',
                    icon: LockKeyhole,
                    summary: 'Password, two-factor and passkeys',
                    keywords: ['password', '2fa', 'two-factor', 'passkey'],
                },
                // docs/16 §1.3: personal tokens, each bound to one team.
                {
                    id: 'api-tokens',
                    label: 'API tokens',
                    icon: KeyRound,
                    summary: 'CLI and API access',
                    keywords: ['cli', 'token', 'api'],
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
                {
                    id: 'git',
                    label: 'Git',
                    icon: GitBranch,
                    summary: 'Providers and connections',
                    keywords: ['github', 'gitlab', 'forgejo', 'repository'],
                },
                {
                    id: 'backups',
                    label: 'Backups',
                    icon: DatabaseBackup,
                    summary: 'Destinations and schedules',
                    keywords: ['s3', 'destination', 'snapshot', 'restore'],
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
                      // Private network + public traffic & certificates.
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
                          label: 'Security',
                          icon: ShieldCheck,
                          summary: 'Server certificates and audit log',
                          keywords: ['mtls', 'certificates', 'audit'],
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

    return (
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
                emptyHint="Try “password”, “token” or “backups”."
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
    );
}
