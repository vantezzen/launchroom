import { Box, Database, FolderClosed, GitPullRequest, Layers, LoaderCircle, Server, Workflow } from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';
import { LogoMark } from '@/components/logo';
import { Device } from '@/components/marketing/device';
import { GitHubMark } from '@/components/marketing/github-mark';
import { StatusIcon } from '@/components/status';
import { cn } from '@/lib/cn';

/*
 * Static compositions built from the dashboard's design and the device
 * renders. Illustrations only: no live data.
 */

/** Card visual: one command, then the server is ready. */
export function InstallVisual() {
    return (
        <div className="flex h-full flex-col items-center justify-center gap-3 px-6 py-5">
            <Device render="server" width={200} />
            <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-2.5 py-1 type-small">
                <StatusIcon state="active" className="size-3" />
                Ready at <span className="font-mono">launch.acme.dev</span>
            </span>
        </div>
    );
}

/** Card visual: launchroom keeps to its folder, the rest of the machine is untouched. */
export function StaysYoursVisual() {
    return (
        <div className="flex h-full flex-col items-center justify-center gap-4 px-6 py-6">
            <Device render="server" width={230} />
            <ul className="flex w-full max-w-[260px] flex-col divide-y divide-border rounded-control border border-border bg-card text-[12px] shadow-card">
                <li className="flex items-center gap-2 px-3 py-2">
                    <LogoMark className="size-3.5" />
                    launchroom
                    <span className="ml-auto text-muted-foreground">one folder</span>
                </li>
                <li className="flex items-center gap-2 px-3 py-2">
                    <FolderClosed className="size-3.5" aria-hidden="true" />
                    Everything else
                    <span className="ml-auto text-muted-foreground">untouched</span>
                </li>
            </ul>
        </div>
    );
}

/** Card visual: free, MIT. */
export function FreeVisual() {
    return (
        <div className="flex h-full flex-col items-center justify-center gap-2 px-6 py-6 text-center">
            <span className="text-[64px] leading-none font-semibold tracking-[-0.05em] text-foreground">$0</span>
            <span className="type-small text-muted-foreground">per server, per seat, per build</span>
            <span className="mt-3 inline-flex items-center gap-2 rounded-full border border-border bg-card px-3 py-1 font-mono text-[12px] shadow-card">
                <GitHubMark className="size-3.5" />
                MIT license
            </span>
        </div>
    );
}

type Row = { icon: ComponentType<{ className?: string }>; name: string; cpu: number; mem: string; replicas?: string };

const vpsServices: Row[] = [
    { icon: Box, name: 'web', cpu: 14, mem: '310 MB', replicas: '2 replicas' },
    { icon: Server, name: 'api', cpu: 9, mem: '240 MB' },
    { icon: Database, name: 'postgres', cpu: 6, mem: '1.1 GB' },
    { icon: Workflow, name: 'worker', cpu: 3, mem: '180 MB' },
    { icon: Layers, name: 'redis', cpu: 1, mem: '64 MB' },
];

function Spark({ points, className }: { points: number[]; className?: string }) {
    const max = Math.max(...points);
    const d = points
        .map((p, i) => `${i === 0 ? 'M' : 'L'}${(i / (points.length - 1)) * 100},${30 - (p / max) * 26}`)
        .join(' ');
    return (
        <svg viewBox="0 0 100 32" preserveAspectRatio="none" className={cn('h-8 w-full', className)} aria-hidden="true">
            <path d={`${d} L100,32 L0,32 Z`} fill="color-mix(in srgb, var(--signal) 14%, transparent)" />
            <path d={d} fill="none" stroke="var(--signal)" strokeWidth="1.5" vectorEffect="non-scaling-stroke" />
        </svg>
    );
}

function Tile({ label, value, unit, children }: { label: string; value: string; unit?: string; children?: ReactNode }) {
    return (
        <div className="flex flex-col gap-1 rounded-[12px] border border-border bg-card p-3">
            <span className="type-small text-muted-foreground">{label}</span>
            <span className="flex items-baseline gap-1">
                <span className="text-[20px] leading-6 font-semibold tracking-[-0.02em] tabular-nums">{value}</span>
                {unit ? <span className="type-small text-muted-foreground">{unit}</span> : null}
            </span>
            {children}
        </div>
    );
}

/**
 * The server page, recreated: the VPS with its load and what runs on it
 * (CPU and memory per service), next to the home machine building.
 */
export function ServerView({ className }: { className?: string }) {
    return (
        <div
            role="img"
            aria-label="Server overview. vps-fra-1 is online at 34% CPU and 5.2 of 8 GB memory, running web, api, postgres, worker and redis with their CPU and memory. home-tower is the build server and is building acme/storefront at 72% CPU."
            className={cn('grid gap-4 lg:grid-cols-[minmax(0,8fr)_minmax(0,4fr)]', className)}
        >
            <div aria-hidden="true" className="overflow-hidden rounded-sheet border border-border bg-card shadow-float">
                <div className="flex flex-col gap-6 p-5 sm:flex-row sm:items-center sm:p-6">
                    <div className="flex items-end justify-center rounded-[14px] bg-recessed px-6 pt-4 pb-2 sm:w-[220px]">
                        <Device render="server" width={180} />
                    </div>
                    <div className="flex flex-col gap-2">
                        <div className="flex items-center gap-2.5">
                            <span className="text-[22px] leading-7 font-semibold tracking-[-0.02em]">vps-fra-1</span>
                            <span className="inline-flex h-6 items-center gap-1.5 rounded-full bg-status-ok-soft pr-2.5 pl-2 type-label text-status-ok">
                                <StatusIcon state="active" className="size-3" />
                                Online
                            </span>
                        </div>
                        <span className="text-[13px] text-muted-foreground">VPS in Frankfurt · runs launchroom</span>
                        <div className="flex flex-wrap gap-1.5">
                            {['Serves traffic', 'Runs apps', '4 vCPU', '8 GB'].map((c) => (
                                <span
                                    key={c}
                                    className="rounded-full border border-border px-2 py-0.5 type-small text-muted-foreground"
                                >
                                    {c}
                                </span>
                            ))}
                        </div>
                    </div>
                </div>
                <div className="grid grid-cols-2 gap-3 border-t border-border bg-recessed p-4 sm:p-5">
                    <Tile label="CPU" value="34" unit="%">
                        <Spark points={[22, 28, 25, 31, 27, 35, 30, 41, 33, 29, 36, 34]} />
                    </Tile>
                    <Tile label="Memory" value="5.2" unit="of 8 GB">
                        <Spark points={[4.6, 4.7, 4.7, 4.8, 5.0, 4.9, 5.1, 5.0, 5.2, 5.1, 5.2, 5.2]} />
                    </Tile>
                </div>
                <div className="border-t border-border">
                    <div className="flex items-baseline gap-2 px-5 pt-4 pb-2 sm:px-6">
                        <span className="type-heading">What runs here</span>
                        <span className="type-small text-muted-foreground">5 services · acme-store</span>
                    </div>
                    <ul className="divide-y divide-border">
                        {vpsServices.map(({ icon: Icon, name, cpu, mem, replicas }) => (
                            <li
                                key={name}
                                className="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_64px] items-center gap-3 px-5 py-2.5 sm:px-6"
                            >
                                <span className="flex min-w-0 items-center gap-2.5">
                                    <span className="flex size-7 shrink-0 items-center justify-center rounded-[8px] border border-border">
                                        <Icon className="size-3.5" />
                                    </span>
                                    <span className="flex min-w-0 flex-col">
                                        <span className="truncate text-[13px] font-medium">{name}</span>
                                        {replicas ? (
                                            <span className="type-small text-muted-foreground">{replicas}</span>
                                        ) : null}
                                    </span>
                                </span>
                                <span className="flex items-center gap-2">
                                    <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-hover">
                                        <span
                                            className="block h-full rounded-full bg-signal"
                                            style={{ width: `${(cpu / 14) * 100}%` }}
                                        />
                                    </span>
                                    <span className="w-9 text-right type-small text-muted-foreground tabular-nums">
                                        {cpu}%
                                    </span>
                                </span>
                                <span className="text-right type-small text-muted-foreground tabular-nums">{mem}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>

            <div
                aria-hidden="true"
                className="flex flex-col overflow-hidden rounded-sheet border border-border bg-card shadow-float"
            >
                <div className="flex items-end justify-center bg-recessed px-6 pt-6 pb-2">
                    <Device render="tower" state="busy" width={170} />
                </div>
                <div className="flex flex-1 flex-col gap-4 p-5">
                    <div className="flex flex-col gap-1">
                        <span className="text-[17px] font-semibold tracking-[-0.01em]">home-tower</span>
                        <span className="text-[13px] text-muted-foreground">Tower PC at home · 16 cores · 64 GB</span>
                    </div>
                    <span className="w-fit rounded-full border border-foreground bg-foreground px-2.5 py-0.5 type-small text-background">
                        Build server
                    </span>
                    <div className="flex flex-col gap-2 rounded-[12px] border border-border bg-recessed p-3">
                        <span className="flex items-center gap-1.5 text-[13px]">
                            <LoaderCircle className="size-3.5 text-status-progress motion-safe:animate-spin" />
                            Building <span className="font-mono">acme/storefront</span>
                        </span>
                        <span className="flex items-center gap-2">
                            <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-hover">
                                <span className="block h-full w-[72%] rounded-full bg-signal" />
                            </span>
                            <span className="type-small text-muted-foreground tabular-nums">CPU 72%</span>
                        </span>
                    </div>
                    <div className="flex flex-col">
                        <span className="pb-1 type-small text-muted-foreground">Recent builds</span>
                        {[
                            ['acme/api', '1m 12s'],
                            ['acme/storefront', '48s'],
                            ['acme/worker', '39s'],
                        ].map(([repo, time]) => (
                            <span
                                key={repo}
                                className="flex items-center gap-2 border-t border-border py-2 text-[12px] first:border-t-0"
                            >
                                <StatusIcon state="active" className="size-3" />
                                <span className="font-mono">{repo}</span>
                                <span className="ml-auto text-muted-foreground tabular-nums">{time}</span>
                            </span>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}

/** A pull request with launchroom's preview comment. */
export function PullRequestPreview({ className }: { className?: string }) {
    return (
        <div
            role="img"
            aria-label="Pull request 142, Checkout with saved cards. launchroom commented: preview is live at its own address with its own database."
            className={cn('flex flex-col gap-3', className)}
        >
            <div
                aria-hidden="true"
                className="flex items-center gap-3 rounded-card border border-border bg-card px-5 py-4 shadow-card"
            >
                <GitPullRequest className="size-4 text-status-ok" />
                <span className="min-w-0 truncate text-sm font-medium">Checkout with saved cards</span>
                <span className="ml-auto font-mono text-[12px] text-muted-foreground">#142</span>
            </div>
            <div
                aria-hidden="true"
                className="ml-6 flex flex-col gap-3 rounded-card border border-border bg-card p-5 shadow-card"
            >
                <div className="flex items-center gap-2">
                    <span className="flex size-6 items-center justify-center rounded-[7px] bg-foreground text-background">
                        <LogoMark className="size-3.5" />
                    </span>
                    <span className="text-[13px] font-medium">launchroom</span>
                    <span className="type-small text-muted-foreground">commented</span>
                </div>
                <div className="flex items-center gap-2 text-sm">
                    <StatusIcon state="active" />
                    Preview is live
                </div>
                <div className="overflow-hidden rounded-[10px] border border-border">
                    {[
                        ['web', 'pr-142.acme-store.dev'],
                        ['api', 'api.pr-142.acme-store.dev'],
                        ['postgres', 'own database, seeded'],
                    ].map(([s, v]) => (
                        <div
                            key={s}
                            className="flex items-center gap-3 border-b border-border px-3 py-2 text-[12px] last:border-b-0"
                        >
                            <span className="w-16 font-mono text-muted-foreground">{s}</span>
                            <span className={cn('truncate', s !== 'postgres' && 'font-mono text-signal')}>{v}</span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
