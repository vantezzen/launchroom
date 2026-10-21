/** Feature sub-pages under /features (app/(home)/features/*), also the header's Features menu. */
export const featurePages = [
    {
        href: '/features/deployments',
        title: 'Deployments and previews',
        short: 'Deployments',
        summary: 'Push to deploy. Every pull request gets its own preview.',
        icon: 'rocket',
    },
    {
        href: '/features/servers',
        title: 'Servers',
        short: 'Servers',
        summary: 'Add a server with one line, including the one at home.',
        icon: 'server',
    },
    {
        href: '/features/databases',
        title: 'Databases and backups',
        short: 'Databases',
        summary: 'Managed databases with encrypted backups to your own S3.',
        icon: 'database',
    },
    {
        href: '/features/observability',
        title: 'Logs and metrics',
        short: 'Observability',
        summary: 'Load, logs and alerts for every server and service.',
        icon: 'activity',
    },
    {
        href: '/features/security',
        title: 'Security',
        short: 'Security',
        summary: 'No SSH and no shell access. Servers run signed instructions.',
        icon: 'shield',
    },
] as const;
