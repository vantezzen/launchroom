import Link from 'next/link';
import { Logo } from '@/components/logo';
import { ButtonLink } from '@/components/marketing/button';
import { GitHubMark } from '@/components/marketing/github-mark';
import { FeaturesMenu, MobileMenu } from '@/components/marketing/header-menus';
import { githubUrl } from '@/lib/shared';

const links = [
    { href: '/docs/how-it-works/architecture', label: 'How it works' },
    { href: '/docs', label: 'Docs' },
];

export function SiteHeader() {
    return (
        <header className="sticky top-0 z-40 border-b border-border bg-background">
            <div className="mx-auto flex h-16 max-w-[1200px] items-center gap-6 px-4 sm:px-6">
                <Link href="/" aria-label="launchroom home" className="flex items-center rounded-md">
                    <Logo />
                </Link>
                <nav aria-label="Main" className="hidden items-center gap-1 md:flex">
                    <FeaturesMenu />
                    {links.map((l) => (
                        <Link
                            key={l.href}
                            href={l.href}
                            className="inline-flex h-9 items-center rounded-md px-3 text-sm text-muted-foreground transition-colors duration-150 hover:text-foreground"
                        >
                            {l.label}
                        </Link>
                    ))}
                </nav>
                <div className="ml-auto flex items-center gap-2">
                    <a
                        href={githubUrl}
                        className="hidden h-9 items-center gap-2 rounded-md px-2.5 text-sm text-muted-foreground transition-colors duration-150 hover:text-foreground sm:inline-flex"
                    >
                        <GitHubMark className="size-4" />
                        GitHub
                    </a>
                    <ButtonLink href="/docs/getting-started/install" size="sm">
                        Install
                    </ButtonLink>
                    <MobileMenu />
                </div>
            </div>
        </header>
    );
}
