import { usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { Page, PageHeader } from '@/components/patterns/page';
import { SettingsNav } from '@/components/patterns/settings-nav';
import type { SettingsNavGroup } from '@/components/patterns/settings-nav';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { index as editApiTokens } from '@/routes/api-tokens';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editDashboardDomain } from '@/routes/dashboard-domain';
import { edit as editDockerfileGeneration } from '@/routes/dockerfile-generation';
import { settings as editBackups } from '@/routes/backups';
import { edit as editGit } from '@/routes/git';
import { edit as editInstanceSecurity } from '@/routes/instance-security';
import { edit as editMove } from '@/routes/move';
import { edit as editNetwork } from '@/routes/network-settings';
import { edit as editNotifications } from '@/routes/notifications';
import { edit as editProfile } from '@/routes/profile';
import { edit as editRegistry } from '@/routes/registry-settings';
import { edit as editSecurity } from '@/routes/security';
import { edit as editTeam } from '@/routes/team';
import { edit as editUpdates } from '@/routes/updates';

/**
 * Team settings (design-system §4.2 `settings`, direction §5.12): one
 * grouped nav (Team · This launchroom · You) and the page title from the
 * active item, so every settings page starts the same way.
 */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { auth, updateAvailable } = usePage().props;
    const owner = auth.user.is_instance_owner === true;

    const item = (
        title: string,
        href: Parameters<typeof isCurrentOrParentUrl>[0],
    ) => ({
        title,
        href,
        active: isCurrentOrParentUrl(href),
    });

    const groups: SettingsNavGroup[] = [
        {
            label: 'Team',
            items: [
                item('Members', editTeam()),
                item('Git', editGit()),
                item('Backups', editBackups()),
                item('Notifications', editNotifications()),
                // docs/20: opt-in "Generate a Dockerfile" (provider for owners, team switch).
                item('Dockerfile generation', editDockerfileGeneration()),
            ],
        },
        // Instance owners only (docs/11 §4); the server authorizes again.
        {
            label: 'This launchroom',
            items: owner
                ? [
                      {
                          ...item('Updates', editUpdates()),
                          dot: updateAvailable ? 'Update ready' : undefined,
                      },
                      item('Registry', editRegistry()),
                      // docs/05 §4.1: the dashboard at its own domain.
                      item('Dashboard domain', editDashboardDomain()),
                      // Private network + public traffic & certificates.
                      item('Network', editNetwork()),
                      // D50: server certificates and the audit log.
                      item('Security', editInstanceSecurity()),
                      // docs/14 §13.4: the dashboard and everything to a new machine.
                      item('Move launchroom', editMove()),
                  ]
                : [],
        },
        {
            label: 'You',
            items: [
                item('Profile', editProfile()),
                item('Security', editSecurity()),
                // docs/16 §1.3: personal tokens, each bound to one team.
                item('API tokens', editApiTokens()),
                item('Appearance', editAppearance()),
            ],
        },
    ];

    const active = groups
        .flatMap((group) => group.items)
        .find((entry) => entry.active);

    return (
        <Page size="settings" nav={<SettingsNav groups={groups} />}>
            <PageHeader title={active?.title ?? 'Settings'} />
            {children}
        </Page>
    );
}
