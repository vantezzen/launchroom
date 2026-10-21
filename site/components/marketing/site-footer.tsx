import Link from 'next/link';
import { Logo } from '@/components/logo';
import { ThemeToggle } from '@/components/theme-toggle';
import { featurePages } from '@/lib/features';
import { githubUrl, licenseUrl } from '@/lib/shared';

const columns = [
    { title: 'Product', links: featurePages.map((f) => ({ href: f.href, label: f.short })) },
    {
        title: 'Docs',
        links: [
            { href: '/docs', label: 'Overview' },
            { href: '/docs/getting-started/install', label: 'Install' },
            { href: '/docs/servers/add-a-server', label: 'Add a server' },
            { href: '/docs/troubleshooting', label: 'Troubleshooting' },
            { href: '/docs/reference/api', label: 'API and CLI' },
        ],
    },
    {
        title: 'Open source',
        links: [
            { href: githubUrl, label: 'GitHub' },
            { href: `${githubUrl}/releases`, label: 'Releases' },
            { href: `${githubUrl}/issues`, label: 'Issues' },
            { href: licenseUrl, label: 'MIT license' },
        ],
    },
];

export function SiteFooter() {
    return (
        <footer className="border-t border-border bg-frame">
            <div className="mx-auto grid max-w-[1200px] gap-10 px-4 py-14 sm:px-6 md:grid-cols-[1.4fr_repeat(3,1fr)]">
                <div className="flex flex-col items-start gap-4">
                    <Logo />
                    <p className="max-w-[30ch] text-sm text-muted-foreground">
                        One place to deploy, on your own server. Free and open source.
                    </p>
                    <ThemeToggle />
                </div>
                {columns.map((c) => (
                    <nav key={c.title} aria-label={c.title} className="flex flex-col gap-2.5">
                        <h2 className="type-label text-foreground">{c.title}</h2>
                        {c.links.map((l) =>
                            l.href.startsWith('http') ? (
                                <a
                                    key={l.href}
                                    href={l.href}
                                    className="w-fit text-sm text-muted-foreground hover:text-foreground"
                                >
                                    {l.label}
                                </a>
                            ) : (
                                <Link
                                    key={l.href}
                                    href={l.href}
                                    className="w-fit text-sm text-muted-foreground hover:text-foreground"
                                >
                                    {l.label}
                                </Link>
                            ),
                        )}
                    </nav>
                ))}
            </div>
            <div className="border-t border-border">
                <div className="mx-auto flex max-w-[1200px] flex-col gap-2 px-4 py-6 text-[13px] text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <p>Free and MIT licensed. There is no paid edition.</p>
                    <p>
                        Made by{' '}
                        <a href="https://github.com/vantezzen" className="text-foreground hover:underline">
                            vantezzen
                        </a>
                    </p>
                </div>
            </div>
        </footer>
    );
}
