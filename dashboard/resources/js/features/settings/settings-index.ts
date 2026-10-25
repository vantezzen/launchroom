import { buildSettingsIndex } from '@/components/settings/search';
import type { SettingDraft, SettingResult } from '@/components/settings/search';
import type { SettingsNavGroup } from '@/components/settings/types';

export type AccountPage =
    | 'profile'
    | 'security'
    | 'api-tokens'
    | 'appearance'
    | 'team'
    | 'git'
    | 'backups'
    | 'notifications'
    | 'dockerfile-generation'
    | 'updates'
    | 'network'
    | 'dashboard-domain'
    | 'registry'
    | 'instance-security'
    | 'move';

/**
 * The search index of `/settings` (docs/08 §1): every page, then the rows
 * people look for. Pages the viewer can't see (This launchroom for
 * everyone but instance owners) drop out with the sidebar's groups.
 * `anchor` is the row's or card's `setting` prop.
 */
export function accountSettingsIndex(
    groups: SettingsNavGroup<AccountPage>[],
): SettingResult<AccountPage>[] {
    const drafts: SettingDraft<AccountPage>[] = [
        // Profile
        {
            target: 'profile',
            label: 'Name',
            hint: 'Shown on deploys and in activity',
            keywords: ['rename', 'display name'],
            anchor: 'name',
        },
        {
            target: 'profile',
            label: 'Email',
            hint: 'The address you sign in with',
            keywords: ['mail', 'address', 'verify'],
            anchor: 'email',
        },
        {
            target: 'profile',
            label: 'Delete account',
            hint: 'Remove your user from launchroom',
            keywords: ['remove', 'close account'],
            anchor: 'delete-account',
        },

        // Security
        {
            target: 'security',
            label: 'Password',
            hint: 'Change your password',
            keywords: ['change password', 'reset'],
            anchor: 'password',
        },
        {
            target: 'security',
            label: 'Two-factor authentication',
            hint: 'Codes from an authenticator app',
            keywords: ['2fa', 'totp', 'mfa', 'otp', 'recovery codes'],
            anchor: 'two-factor',
        },
        {
            target: 'security',
            label: 'Passkeys',
            hint: 'Sign in with your device',
            keywords: ['webauthn', 'fido', 'touch id', 'face id'],
            anchor: 'passkeys',
        },

        // API tokens
        {
            target: 'api-tokens',
            label: 'Use the CLI',
            hint: 'Install and sign in',
            keywords: ['cli', 'terminal', 'install', 'login'],
            anchor: 'cli',
        },
        {
            target: 'api-tokens',
            label: 'Your tokens',
            hint: 'Create and revoke tokens',
            keywords: ['new token', 'revoke', 'personal access token'],
            anchor: 'tokens',
        },

        // Appearance
        {
            target: 'appearance',
            label: 'Theme',
            hint: 'Light, dark or system',
            keywords: ['dark mode', 'light mode', 'colors'],
        },

        // Members
        {
            target: 'team',
            label: 'Invite people',
            hint: 'Add someone to this team',
            keywords: ['invite', 'add member', 'invitation'],
        },
        {
            target: 'team',
            label: 'Roles',
            hint: 'Owner, admin, developer or viewer',
            keywords: ['role', 'permissions', 'access', 'admin', 'viewer'],
        },
        {
            target: 'team',
            label: 'Console recording',
            hint: 'Record console sessions',
            keywords: ['transcript', 'audit', 'console'],
            anchor: 'console-recording',
        },

        // Git
        {
            target: 'git',
            label: 'Connect a git provider',
            hint: 'GitHub, GitLab, Gitea or Forgejo',
            keywords: ['github app', 'gitlab', 'gitea', 'forgejo', 'oauth'],
        },
        {
            target: 'git',
            label: 'GitHub webhook URL',
            hint: 'Where GitHub sends pushes',
            keywords: ['webhook', 'push'],
        },

        // Backups
        {
            target: 'backups',
            label: 'Destinations',
            hint: 'Where snapshots are stored',
            keywords: ['s3', 'bucket', 'storage', 'b2', 'r2'],
        },
        {
            target: 'backups',
            label: 'Schedules',
            hint: 'How often each database is backed up',
            keywords: ['schedule', 'retention', 'cron', 'protected'],
        },
        {
            target: 'backups',
            label: 'Recovery kit',
            hint: 'Restore launchroom after losing its server',
            keywords: ['recovery', 'disaster', 'passphrase'],
            anchor: 'recovery-kit',
        },

        // Notifications
        {
            target: 'notifications',
            label: 'Channels',
            hint: 'Email, Slack, Discord or a webhook',
            keywords: ['email', 'slack', 'discord', 'webhook', 'channel'],
        },
        {
            target: 'notifications',
            label: 'Alert rules',
            hint: 'What tells you and when',
            keywords: [
                'rules',
                'failed deploy',
                'unhealthy',
                'error rate',
                'offline',
            ],
        },

        // Dockerfile generation
        {
            target: 'dockerfile-generation',
            label: 'Model provider',
            hint: 'Which model suggests Dockerfiles',
            keywords: ['anthropic', 'claude', 'api key', 'ai'],
        },

        // Updates
        {
            target: 'updates',
            label: 'Automatic updates',
            hint: 'When launchroom updates itself',
            keywords: ['auto update', 'upgrade', 'release', 'version'],
        },

        // Network
        {
            target: 'network',
            label: 'Apps domain',
            hint: 'The domain new apps get addresses under',
            keywords: ['wildcard', 'dns', 'sslip', 'platform domain'],
        },
        {
            target: 'network',
            label: 'Private network',
            hint: 'How your servers reach each other',
            keywords: ['mesh', 'tailscale', 'headscale', 'acl'],
        },
        {
            target: 'network',
            label: 'Certificates',
            hint: 'HTTPS certificates for your domains',
            keywords: ['tls', 'ssl', 'https', 'lets encrypt', 'acme'],
        },

        // Dashboard domain
        {
            target: 'dashboard-domain',
            label: 'Dashboard address',
            hint: 'Serve the dashboard at your own domain',
            keywords: ['url', 'domain', 'https'],
        },

        // Registry
        {
            target: 'registry',
            label: 'Image storage',
            hint: 'On the server or in a bucket',
            keywords: ['bucket', 's3', 'images', 'cache'],
        },

        // Security (this launchroom)
        {
            target: 'instance-security',
            label: 'Server certificates',
            hint: 'How servers prove who they are',
            keywords: ['mtls', 'client certificates', 'reissue'],
        },
        {
            target: 'instance-security',
            label: 'Audit log',
            hint: 'Who changed what, and when',
            keywords: ['audit', 'log', 'sink', 'siem', 'export'],
            anchor: 'audit-log',
        },
    ];

    return buildSettingsIndex(drafts, groups, { pages: true });
}
