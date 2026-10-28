import { ArrowDown, Check, EyeOff, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { Screenshot } from '@/components/screenshot';
import { cn } from '@/lib/cn';

/*
 * Request analytics and the security center on the marketing pages: framed
 * screenshots (cut to the part that matters with `region`) and two static
 * pieces, what the edge keeps of a request and the rules behind an alert.
 */

type Region = { x: number; y: number; width: number; height: number };

/** Regions of the screenshots in lib/screenshots.json, in stored pixels. */
export const regions = {
    analyticsTop: { x: 0, y: 0, width: 1440, height: 800 },
    analyticsLists: { x: 272, y: 800, width: 1146, height: 510 },
    analyticsPaths: { x: 280, y: 808, width: 740, height: 430 },
    overviewChartLeft: { x: 280, y: 345, width: 740, height: 430 },
    firewallChart: { x: 24, y: 88, width: 1126, height: 470 },
    findingFound: { x: 466, y: 735, width: 600, height: 400 },
    inventoryLodash: { x: 16, y: 130, width: 1150, height: 210 },
    analyticsPhone: { x: 280, y: 130, width: 740, height: 660 },
    securityPhone: { x: 280, y: 160, width: 740, height: 660 },
    overviewChart: { x: 272, y: 340, width: 1146, height: 405 },
    overviewProjects: { x: 272, y: 760, width: 1146, height: 470 },
    securityTop: { x: 0, y: 0, width: 1440, height: 1125 },
    securityHero: { x: 272, y: 150, width: 1146, height: 290 },
    findingInvestigation: { x: 462, y: 690, width: 764, height: 430 },
    findingRules: { x: 462, y: 192, width: 764, height: 490 },
    findingFull: { x: 440, y: 80, width: 808, height: 1620 },
} satisfies Record<string, Region>;

/**
 * A large screenshot in the hero's frame: a soft outer frame around a rounded
 * image. `phoneRegion` shows a closer cut below 640 px, where the whole page
 * would be too small to read.
 */
export function ShotFrame({
    id,
    region,
    phoneRegion,
    sizes = '(min-width: 1200px) 1180px, 100vw',
    className,
}: {
    id: string;
    region?: Region;
    phoneRegion?: Region;
    sizes?: string;
    className?: string;
}) {
    const inner = '[&>div]:rounded-[14px] [&>div]:border [&>div]:border-border';
    return (
        <div className={cn('rounded-[20px] border border-border bg-frame p-1.5 shadow-float sm:p-2', className)}>
            <Screenshot
                id={id}
                region={region}
                caption={false}
                frameless
                sizes={sizes}
                className={cn(inner, phoneRegion && 'hidden sm:block')}
            />
            {phoneRegion ? (
                <Screenshot
                    id={id}
                    region={phoneRegion}
                    caption={false}
                    frameless
                    sizes="100vw"
                    className={cn(inner, 'sm:hidden')}
                />
            ) : null}
        </div>
    );
}

/** A card with a cut-out of a screenshot on top and a short text below. */
export function ShotCard({
    id,
    region,
    title,
    children,
    sizes = '(min-width: 1024px) 600px, 100vw',
    className,
}: {
    id: string;
    region?: Region;
    title: string;
    children: ReactNode;
    sizes?: string;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-col overflow-hidden rounded-card border border-border bg-card shadow-card',
                className,
            )}
        >
            <div className="border-b border-border bg-recessed p-3 sm:p-4">
                <Screenshot id={id} region={region} caption={false} sizes={sizes} className="[&>div]:rounded-[10px]" />
            </div>
            <div className="flex flex-col gap-1.5 p-5 sm:p-6">
                <h3 className="type-heading">{title}</h3>
                <p className="text-[15px] leading-6 text-muted-foreground">{children}</p>
            </div>
        </div>
    );
}

/** A plain card with a title and a list of short facts, each with a check. */
export function CheckCard({
    title,
    items,
    icon,
    children,
    className,
}: {
    title: string;
    items: string[];
    icon?: ReactNode;
    children?: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('flex flex-col gap-5 rounded-card border border-border bg-card p-6 shadow-card', className)}>
            <div className="flex items-center gap-3">
                {icon ? (
                    <span className="flex size-9 items-center justify-center rounded-[10px] border border-border bg-recessed text-foreground">
                        {icon}
                    </span>
                ) : null}
                <h3 className="type-heading">{title}</h3>
            </div>
            <ul className="flex flex-col gap-3 text-[15px]">
                {items.map((i) => (
                    <li key={i} className="flex gap-3">
                        <Check className="mt-1 size-4 shrink-0 text-status-ok" aria-hidden="true" />
                        <span>{i}</span>
                    </li>
                ))}
            </ul>
            {children ? <p className="mt-auto text-[14px] leading-6 text-muted-foreground">{children}</p> : null}
        </div>
    );
}

const privacy = [
    'Counted at the edge, on your server',
    'No IP addresses or locations',
    'No query strings, IDs become :id',
    'No cookies and no script in your app',
];

/** The privacy facts of request analytics as one wide strip. */
export function PrivacyStrip({ className }: { className?: string }) {
    return (
        <div
            className={cn(
                'flex flex-col gap-5 rounded-card border border-border bg-card p-6 shadow-card lg:flex-row lg:items-center lg:gap-10',
                className,
            )}
        >
            <div className="flex shrink-0 items-center gap-3">
                <span className="flex size-9 items-center justify-center rounded-[10px] border border-border bg-recessed text-foreground">
                    <EyeOff className="size-[18px]" aria-hidden="true" />
                </span>
                <h3 className="type-heading">Private by design</h3>
            </div>
            <ul className="grid flex-1 gap-3 text-[15px] sm:grid-cols-2 lg:grid-cols-4 lg:gap-6">
                {privacy.map((i) => (
                    <li key={i} className="flex gap-2.5">
                        <Check className="mt-1 size-4 shrink-0 text-status-ok" aria-hidden="true" />
                        <span>{i}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** What the edge keeps of one request: the raw line on top, the counted facts below. */
export function EdgeReduction({ className }: { className?: string }) {
    const kept: Array<[string, string]> = [
        ['Path', '/products/:id'],
        ['Status', '200'],
        ['Referrer', 'l.instagram.com'],
        ['Client', 'Browser'],
    ];
    return (
        <div
            role="img"
            aria-label="One request as it arrives: GET /products/48213?utm_source=newsletter from 203.0.113.7 with a cookie, a full referrer URL and a browser user agent. What the edge counts: the path /products/:id, status 200, the referrer host l.instagram.com and the client class Browser. The address, query string, cookie and user agent are dropped."
            className={cn('overflow-hidden rounded-card border border-border bg-card shadow-card', className)}
        >
            <div aria-hidden="true">
                <div className="flex items-center justify-between border-b border-border px-5 py-3">
                    <p className="text-sm font-medium">Request at the edge</p>
                    <span className="type-small text-muted-foreground">fra-1</span>
                </div>
                <dl className="grid grid-cols-[88px_minmax(0,1fr)] gap-x-4 gap-y-2 px-5 py-4 font-mono text-[12.5px] leading-5">
                    <dt className="text-muted-foreground">Request</dt>
                    <dd className="truncate">
                        GET /products/48213<Dropped>?utm_source=newsletter</Dropped>
                    </dd>
                    <dt className="text-muted-foreground">Address</dt>
                    <dd>
                        <Dropped>203.0.113.7</Dropped>
                    </dd>
                    <dt className="text-muted-foreground">Cookie</dt>
                    <dd className="truncate">
                        <Dropped>session=8f2c…e41a</Dropped>
                    </dd>
                    <dt className="text-muted-foreground">Referer</dt>
                    <dd className="truncate">
                        https://l.instagram.com<Dropped>/?u=https%3A%2F%2Fshop…</Dropped>
                    </dd>
                    <dt className="text-muted-foreground">User agent</dt>
                    <dd className="truncate">
                        Mozilla<Dropped>/5.0 (Macintosh; Intel Mac OS X 14_6)…</Dropped>
                    </dd>
                </dl>
                <div className="flex items-center gap-2 border-y border-border bg-recessed px-5 py-2.5 type-small text-muted-foreground">
                    <ArrowDown className="size-3.5" aria-hidden="true" />
                    Counted per path, every 10 minutes
                </div>
                <ul className="grid grid-cols-2 gap-px bg-border">
                    {kept.map(([k, v]) => (
                        <li key={k} className="flex flex-col gap-0.5 bg-card px-5 py-3">
                            <span className="type-small text-muted-foreground">{k}</span>
                            <span className="truncate font-mono text-[13px]">{v}</span>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

function Dropped({ children }: { children: ReactNode }) {
    return <span className="text-muted-foreground/70 line-through decoration-status-error/70">{children}</span>;
}

const rules: Array<[string, string]> = [
    ['Runs in a live deployment', 'Previews and old releases don’t count'],
    ['Can run while requests are served', 'Not a dev dependency or browser-only code'],
    ['High or critical severity', 'From the advisory’s rating or CVSS score'],
    ['A fixed version exists', 'So there is something to do'],
    ['Reachable over the network', 'From the attack vector of the advisory'],
    ['The service has a public domain', 'Workers and databases are listed quietly'],
];

/** The "Action needed" rules as a checklist (docs: security/vulnerabilities). */
export function ActionRules({ className }: { className?: string }) {
    return (
        <div className={cn('overflow-hidden rounded-card border border-border bg-card shadow-card', className)}>
            <div className="flex items-center justify-between gap-4 border-b border-border px-5 py-4">
                <p className="text-sm font-medium">Action needed when all of these hold</p>
                <span className="inline-flex h-6 shrink-0 items-center gap-1.5 rounded-full bg-status-error-soft pr-2.5 pl-2 type-label text-status-error">
                    <X className="size-3" strokeWidth={2.5} aria-hidden="true" />
                    Notifies
                </span>
            </div>
            <ul className="flex flex-col px-5 py-2">
                {rules.map(([rule, why]) => (
                    <li key={rule} className="flex gap-3 border-b border-border py-3 last:border-b-0">
                        <span className="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full bg-status-ok text-white dark:text-black">
                            <Check className="size-2.5" strokeWidth={3} aria-hidden="true" />
                        </span>
                        <span className="flex min-w-0 flex-col">
                            <span className="text-[14px] leading-5 font-medium">{rule}</span>
                            <span className="type-small text-muted-foreground">{why}</span>
                        </span>
                    </li>
                ))}
            </ul>
            <div className="flex flex-col gap-1 border-t border-border bg-recessed px-5 py-3 type-small text-muted-foreground">
                <span>
                    A flaw that is <span className="font-medium text-foreground">known to be exploited</span> only needs
                    the first two.
                </span>
                <span>Everything else is listed under Worth knowing and never notifies.</span>
            </div>
        </div>
    );
}
