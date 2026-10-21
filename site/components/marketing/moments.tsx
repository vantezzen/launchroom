'use client';
import { Check, Database, Globe, LoaderCircle, Plus } from 'lucide-react';
import { useEffect, useRef, type ReactNode } from 'react';
import { Device } from '@/components/marketing/device';
import { cn } from '@/lib/cn';

/*
 * Product moments: small recreations of the dashboard that play once when
 * they scroll into view. The markup is the end state; app/global.css holds
 * the start state and the timeline (`.lr-moment`), all inside
 * `prefers-reduced-motion: no-preference`. Without JavaScript or with
 * reduced motion the end state shows right away.
 */
function Moment({ className, label, children }: { className?: string; label: string; children: ReactNode }) {
    const ref = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const el = ref.current;
        if (!el || !('IntersectionObserver' in window)) return;
        el.dataset.armed = '';
        const io = new IntersectionObserver(
            (entries) => {
                if (entries.some((e) => e.isIntersecting)) {
                    el.dataset.play = '';
                    io.disconnect();
                }
            },
            { threshold: 0.45 },
        );
        io.observe(el);
        return () => io.disconnect();
    }, []);
    return (
        <div ref={ref} role="img" aria-label={label} className={cn('lr-moment', className)}>
            <div aria-hidden="true" className="contents">
                {children}
            </div>
        </div>
    );
}

function Node({
    icon,
    name,
    subtitle,
    children,
    className,
}: {
    icon: ReactNode;
    name: string;
    subtitle: string;
    children?: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('w-full rounded-card border border-border bg-card shadow-card', className)}>
            <div className="flex flex-col gap-3 p-4">
                <div className="flex items-center gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] border border-border text-foreground">
                        {icon}
                    </span>
                    <span className="flex min-w-0 flex-col">
                        <span className="truncate text-[14px] leading-5 font-semibold">{name}</span>
                        <span className="truncate type-small text-muted-foreground">{subtitle}</span>
                    </span>
                </div>
                {children}
            </div>
        </div>
    );
}

function Dot({ className }: { className?: string }) {
    return <span className={cn('size-2 shrink-0 rounded-full bg-status-ok', className)} />;
}

/** Drop a database on the canvas: it lands, the connection draws itself, the app says "Connected". */
export function ConnectMoment({ className }: { className?: string }) {
    return (
        <Moment
            className={cn(
                'relative overflow-hidden rounded-sheet border border-border bg-background shadow-float',
                className,
            )}
            label="On the canvas, a PostgreSQL database is added next to the web app. A connection draws itself between them, and the app shows Connected to postgres."
        >
            <div className="absolute inset-0 [background-image:radial-gradient(var(--border-strong)_1px,transparent_1px)] [background-size:20px_20px]" />
            <div className="relative grid grid-cols-1 items-center gap-0 p-6 sm:p-10 md:grid-cols-[minmax(0,1fr)_minmax(80px,140px)_minmax(0,1fr)]">
                <Node icon={<Globe className="size-[18px]" />} name="web" subtitle="acme/storefront · Next.js">
                    <div className="flex flex-col gap-1.5 type-small">
                        <span className="flex items-center gap-1.5">
                            <Dot />
                            Live
                            <span className="text-muted-foreground">· acme-store.com</span>
                        </span>
                        <span className="lr-c-connected flex items-center gap-1.5 text-status-ok">
                            <Check className="size-3.5" strokeWidth={2.5} />
                            Connected to postgres
                        </span>
                    </div>
                </Node>

                <div className="relative flex h-16 items-center justify-center md:h-auto">
                    <span className="lr-c-line absolute top-0 bottom-0 left-1/2 w-[1.5px] -translate-x-1/2 bg-foreground/35 md:top-1/2 md:right-0 md:bottom-auto md:left-0 md:h-[1.5px] md:w-auto md:translate-x-0" />
                    <span className="lr-c-pill relative rounded-full border border-border-strong bg-card px-2 py-0.5 font-mono text-[11px] text-muted-foreground">
                        DATABASE_URL
                    </span>
                </div>

                <div className="relative">
                    <div className="lr-c-ghost absolute inset-0 flex items-center justify-center gap-2 rounded-card border border-dashed border-border-strong text-[13px] text-muted-foreground opacity-0">
                        <Plus className="size-4" />
                        Database
                    </div>
                    <Node
                        className="lr-c-db"
                        icon={<Database className="size-[18px]" />}
                        name="postgres"
                        subtitle="PostgreSQL 18"
                    >
                        <div className="flex flex-col gap-1.5 type-small">
                            <span className="flex items-center gap-1.5">
                                <Dot />
                                Running
                            </span>
                            <span className="text-muted-foreground">Backed up daily</span>
                        </div>
                    </Node>
                </div>
            </div>
        </Moment>
    );
}

/** A machine at home runs one line, joins, and becomes the build server while the VPS keeps serving. */
export function JoinMoment({ className }: { className?: string }) {
    return (
        <Moment
            className={cn('overflow-hidden rounded-sheet border border-border bg-recessed', className)}
            label="A command runs on a tower PC at home. It joins the VPS over a private network and becomes the build server, while the VPS keeps serving traffic."
        >
            <div className="flex flex-col gap-8 px-5 pt-6 pb-8 sm:px-10 sm:pt-8">
                <div className="mx-auto w-full max-w-[560px] rounded-control border border-border bg-card px-4 py-3 font-mono text-[12px] leading-5 shadow-card">
                    <span className="text-muted-foreground">home-tower $ </span>
                    <span className="lr-j-type break-all text-foreground">
                        curl -fsSL https://launch.acme.dev/install/server.sh | sh
                    </span>
                </div>

                <div className="mx-auto flex w-full max-w-[720px] items-start justify-center">
                    <div className="flex w-[44%] max-w-[280px] flex-col items-center gap-3 sm:w-[40%]">
                        <Device render="server" width={280} fluid sizes="280px" />
                        <div className="flex flex-col items-center gap-1.5 text-center">
                            <span className="font-mono text-[13px] font-medium">vps-fra-1</span>
                            <span className="flex items-center gap-1.5 type-small">
                                <Dot />
                                Online
                            </span>
                            <span className="lr-j-role rounded-full border border-border bg-card px-2.5 py-0.5 type-small whitespace-nowrap">
                                Serves your apps
                            </span>
                        </div>
                    </div>

                    <div className="relative z-10 -mr-[13%] mt-[calc(min(40%,280px)*0.43)] flex flex-1 flex-col items-center">
                        <span className="lr-j-linklabel mb-1.5 font-mono text-[10px] whitespace-nowrap text-signal sm:text-[11px]">
                            private network
                        </span>
                        <span className="lr-j-link block w-full border-t-[1.5px] border-dashed border-signal" />
                    </div>

                    <div className="flex w-[44%] max-w-[280px] flex-col items-center gap-3 sm:w-[40%]">
                        <span className="relative flex w-full max-w-[280px]">
                            <Device
                                render="tower"
                                state="off"
                                width={280}
                                fluid
                                className="lr-j-tower-off absolute inset-0"
                                sizes="280px"
                            />
                            <Device render="tower" width={280} fluid className="lr-j-tower block" sizes="280px" />
                        </span>
                        <div className="flex flex-col items-center gap-1.5 text-center">
                            <span className="font-mono text-[13px] font-medium">home-tower</span>
                            <span className="relative flex h-4 items-center justify-center type-small">
                                <span className="lr-j-status-off absolute flex items-center gap-1.5 whitespace-nowrap text-muted-foreground opacity-0">
                                    <span className="size-2 rounded-full border-[1.5px] border-status-idle" />
                                    Not connected
                                </span>
                                <span className="lr-j-status flex items-center gap-1.5 whitespace-nowrap">
                                    <LoaderCircle className="size-3.5 text-status-progress motion-safe:animate-spin" />
                                    Building
                                </span>
                            </span>
                            <span className="lr-j-role rounded-full border border-foreground bg-foreground px-2.5 py-0.5 type-small whitespace-nowrap text-background">
                                Build server
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </Moment>
    );
}
