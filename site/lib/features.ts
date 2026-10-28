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
        href: '/features/analytics',
        title: 'Analytics and metrics',
        short: 'Analytics',
        summary: 'Traffic per service without a tracking script, plus load, logs and alerts.',
        icon: 'chart',
    },
    {
        href: '/features/security',
        title: 'Security',
        short: 'Security',
        summary: 'A web firewall and vulnerability checks, on from the first deploy.',
        icon: 'shield',
    },
] as const;
