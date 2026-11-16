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
import { edit as editTeam } from '@/routes/team';
import { edit as editUpdates } from '@/routes/updates';

export type SettingsPageEntry = {
    label: string;
    /** The settings group, shown as a quiet path after the label. */
    group: 'Account' | 'Team' | 'This launchroom';
    url: () => string;
    keywords: string;
    /** This launchroom's pages: instance owners only (the server checks too). */
    ownerOnly?: boolean;
};

/**
 * The `/settings` pages ⌘K finds while you type ("API tokens" with
 * "Account" after it). Labels and groups follow the settings sidebar
 * (features/settings/settings-layout.tsx); rows inside a page stay in the
 * settings search.
 */
export const SETTINGS_PAGES: SettingsPageEntry[] = [
    {
        label: 'Profile',
        group: 'Account',
        url: () => editProfile().url,
        keywords: 'name email delete account',
    },
    {
        label: 'Sign-in',
        group: 'Account',
        url: () => editSecurity().url,
        keywords: 'password two-factor 2fa passkeys security',
    },
    {
        label: 'API tokens',
        group: 'Account',
        url: () => editApiTokens().url,
        keywords: 'cli token api access',
    },
    {
        label: 'Appearance',
        group: 'Account',
        url: () => editAppearance().url,
        keywords: 'theme dark mode light mode',
    },
    {
        label: 'Members',
        group: 'Team',
        url: () => editTeam().url,
        keywords: 'team invite people roles',
    },
    {
        label: 'Git',
        group: 'Team',
        url: () => editGit().url,
        keywords: 'github gitlab forgejo repository',
    },
    {
        label: 'Backups',
        group: 'Team',
        url: () => editBackups().url,
        keywords: 's3 destination snapshot restore',
    },
    {
        label: 'Notifications',
        group: 'Team',
        url: () => editNotifications().url,
        keywords: 'alerts email slack webhook channels rules',
    },
    {
        label: 'Dockerfile generation',
        group: 'Team',
        url: () => editDockerfileGeneration().url,
        keywords: 'ai llm model dockerfile',
    },
    {
        label: 'Updates',
        group: 'This launchroom',
        url: () => editUpdates().url,
        keywords: 'version upgrade release',
        ownerOnly: true,
    },
    {
        label: 'Network',
        group: 'This launchroom',
        url: () => editNetwork().url,
        keywords: 'apps domain mesh private network tls certificates',
        ownerOnly: true,
    },
    {
        label: 'Dashboard domain',
        group: 'This launchroom',
        url: () => editDashboardDomain().url,
        keywords: 'url address https',
        ownerOnly: true,
    },
    {
        label: 'Registry',
        group: 'This launchroom',
        url: () => editRegistry().url,
        keywords: 'images bucket s3 storage',
        ownerOnly: true,
    },
    {
        label: 'Certificates & audit',
        group: 'This launchroom',
        url: () => editInstanceSecurity().url,
        keywords: 'mtls certificates audit log vulnerability osv',
        ownerOnly: true,
    },
    {
        label: 'Move launchroom',
        group: 'This launchroom',
        url: () => editMove().url,
        keywords: 'migrate new machine',
        ownerOnly: true,
    },
];
