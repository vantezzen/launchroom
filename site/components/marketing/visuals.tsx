import type { ReactNode } from 'react';
import { Check, GitCommitHorizontal, GitPullRequest, KeyRound, Lock, ShieldCheck } from 'lucide-react';
import { Status, StatusIcon, StatusPill, type StatusState } from '@/components/status';
import { cn } from '@/lib/cn';

/*
 * Static recreations of dashboard pieces (deployment steps, server cards,
 * compare & promote, recovery kit), built from the dashboard's tokens and
 * components. They are illustrations: no live data, no interaction.
 */

/** A card in the dashboard's level-1 style. */
export function Panel({ className, children, label }: { className?: string; children: ReactNode; label?: string }) {
    return (
        <div
            className={cn('rounded-card border border-border bg-card shadow-card', className)}
            aria-label={label}
            role={label ? 'img' : undefined}
        >
            {children}
        </div>
    );
}

const steps: Array<{ name: string; detail: string; time: string; state: StatusState }> = [
    { name: 'Queued', detail: 'On home-desktop', time: '1s', state: 'active' },
    { name: 'Clone', detail: 'acme/storefront @ 3b7d5e1', time: '2s', state: 'active' },
    { name: 'Build', detail: 'Next.js detected · npm ci · next build', time: '48s', state: 'active' },
    { name: 'Push image', detail: 'sha256:9f2c…e41a · 182 MB', time: '6s', state: 'active' },
    { name: 'Start', detail: '2 replicas next to the running version', time: '4s', state: 'active' },
    { name: 'Health check', detail: 'GET / answered 200 · 2 of 2', time: '3s', state: 'active' },
    { name: 'Switch traffic', detail: 'Old version drains its requests', time: '1s', state: 'active' },
];

/** The deployment step list (design-system §6 StepList). */
export function DeployTimeline({ className }: { className?: string }) {
    return (
        <Panel
            className={cn('overflow-hidden', className)}
            label="Deployment of web: queued, cloned, built, image pushed, started, health check passed, traffic switched. Live after 1 minute 5 seconds."
        >
            <div className="flex items-center gap-3 border-b border-border px-5 py-4">
                <GitCommitHorizontal className="size-4 text-muted-foreground" aria-hidden="true" />
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">fix: order totals with discounts</p>
                    <p className="truncate type-small text-muted-foreground">
                        <span className="font-mono">3b7d5e1</span> · main · web · production
                    </p>
                </div>
                <StatusPill state="active" label="Live" />
            </div>
            <ol className="flex flex-col py-2">
                {steps.map((s, i) => (
                    <li key={s.name} className="relative flex items-start gap-3 px-5 py-2">
                        {i < steps.length - 1 ? (
                            <span
                                aria-hidden="true"
                                className="absolute top-7 bottom-[-6px] left-[27px] w-px bg-border"
                            />
                        ) : null}
                        <span className="relative z-10 mt-0.5 flex size-4 items-center justify-center rounded-full bg-status-ok text-white dark:text-black">
                            <Check className="size-2.5" strokeWidth={3} aria-hidden="true" />
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="block text-[13px] leading-5 font-medium">{s.name}</span>
                            <span className="block truncate type-small text-muted-foreground">{s.detail}</span>
                        </span>
                        <span className="type-small text-muted-foreground tabular-nums">{s.time}</span>
                    </li>
                ))}
            </ol>
            <div className="flex items-center justify-between border-t border-border bg-recessed px-5 py-3 type-small text-muted-foreground">
                <span>Total 1m 5s</span>
                <span>Previous image kept for instant rollback</span>
            </div>
        </Panel>
    );
}

const diff: Array<{ sign: '+' | '−' | '~'; key: string; value: string }> = [
    { sign: '~', key: 'api · NEXT_CACHE', value: 'redis → valkey' },
    { sign: '+', key: 'api · STRIPE_WEBHOOK_URL', value: 'https://acme-store.com/hooks' },
    { sign: '~', key: 'web · replicas', value: '2 → 3' },
    { sign: '−', key: 'worker · LEGACY_QUEUE', value: 'orders-v1' },
];

/** Compare & promote between environments (docs: environments). */
export function PromoteDiff({ className }: { className?: string }) {
    return (
        <Panel
            className={cn('overflow-hidden', className)}
            label="Compare staging with production: four changes selected to promote, then deploy."
        >
            <div className="flex flex-wrap items-center gap-2 border-b border-border px-5 py-4 text-sm">
                <span className="rounded-full border border-border px-2.5 py-0.5 type-label">staging</span>
                <span className="text-muted-foreground" aria-hidden="true">
                    →
                </span>
                <span className="rounded-full bg-primary px-2.5 py-0.5 type-label text-primary-foreground">
                    production
                </span>
                <span className="ml-auto type-small text-muted-foreground">4 changes</span>
            </div>
            <ul className="flex flex-col font-mono text-[12.5px]">
                {diff.map((d) => (
                    <li
                        key={d.key}
                        className={cn(
                            'flex items-center gap-3 border-b border-border px-5 py-2.5 last:border-b-0',
                            d.sign === '+' && 'bg-diff-add',
                            d.sign === '−' && 'bg-diff-remove',
                            d.sign === '~' && 'bg-signal-soft/60',
                        )}
                    >
                        <span className="w-3 text-muted-foreground" aria-hidden="true">
                            {d.sign}
                        </span>
                        <span className="sr-only">
                            {d.sign === '+' ? 'Added' : d.sign === '−' ? 'Removed' : 'Changed'}:
                        </span>
                        <span className="min-w-0 flex-1 truncate">{d.key}</span>
                        <span className="hidden truncate text-muted-foreground sm:inline">{d.value}</span>
                        <span
                            className="flex size-4 items-center justify-center rounded-[4px] bg-primary text-primary-foreground"
                            aria-hidden="true"
                        >
                            <Check className="size-3" strokeWidth={3} />
                        </span>
                    </li>
                ))}
            </ul>
            <div className="flex items-center justify-end gap-2 border-t border-border px-5 py-3">
                <span className="rounded-control px-3 py-1.5 text-[13px] text-muted-foreground">Cancel</span>
                <span className="rounded-control bg-primary px-3 py-1.5 text-[13px] font-medium text-primary-foreground">
                    Promote and deploy
                </span>
            </div>
        </Panel>
    );
}

/** A pull request preview card. */
export function PreviewCard({ className }: { className?: string }) {
    return (
        <Panel
            className={cn('flex flex-col gap-3 p-5', className)}
            label="Preview for pull request 142: live at its own URL with its own database."
        >
            <div className="flex items-center gap-2">
                <GitPullRequest className="size-4 text-muted-foreground" aria-hidden="true" />
                <span className="text-sm font-medium">#142 Checkout with saved cards</span>
                <StatusPill state="active" label="Live" className="ml-auto" />
            </div>
            <p className="truncate font-mono text-[12.5px] text-signal">pr-142.acme-store.preview.acme.dev</p>
            <div className="flex flex-wrap gap-1.5 type-small text-muted-foreground">
                <span className="rounded-full border border-border px-2 py-0.5">Own database, seeded</span>
                <span className="rounded-full border border-border px-2 py-0.5">Protected link</span>
                <span className="rounded-full border border-border px-2 py-0.5">Comment on the PR</span>
            </div>
        </Panel>
    );
}

/** The recovery kit and backup status. */
export function RecoveryCard({ className }: { className?: string }) {
    const rows = [
        { label: 'Control plane', value: 'Backed up 12 min ago' },
        { label: 'orders-db · PostgreSQL', value: 'Any point in the last 7 days' },
        { label: 'uploads · volume', value: 'Backed up 1h ago' },
    ];
    return (
        <Panel
            className={cn('overflow-hidden', className)}
            label="Backups: control plane, database and volume backed up, recovery kit downloaded."
        >
            <div className="flex items-center gap-3 border-b border-border px-5 py-4">
                <span className="flex size-9 items-center justify-center rounded-[10px] border border-border">
                    <KeyRound className="size-[18px]" aria-hidden="true" />
                </span>
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium">Recovery kit</p>
                    <p className="type-small text-muted-foreground">Downloaded · keep it outside this server</p>
                </div>
                <Lock className="size-4 text-muted-foreground" aria-hidden="true" />
            </div>
            <ul className="flex flex-col">
                {rows.map((r) => (
                    <li
                        key={r.label}
                        className="flex items-center gap-3 border-b border-border px-5 py-3 text-[13px] last:border-b-0"
                    >
                        <StatusIcon state="active" />
                        <span className="min-w-0 flex-1 truncate">{r.label}</span>
                        <span className="truncate text-muted-foreground">{r.value}</span>
                    </li>
                ))}
            </ul>
            <div className="border-t border-border bg-recessed px-5 py-3 font-mono text-[12px] break-all text-muted-foreground">
                curl -fsSL https://launchroom.vantezzen.io/install | sh -s -- --restore
            </div>
        </Panel>
    );
}

/** Signed instructions: what crosses the wire between the dashboard and a server. */
export function SignedStateCard({ className }: { className?: string }) {
    const rows = [
        '3 workloads · 2 routes · 1 certificate',
        'Firewall: 2 mesh peers allowed',
        'Version 418 · signed Ed25519',
    ];
    return (
        <Panel
            className={cn('overflow-hidden', className)}
            label="A signed desired state for one server: workloads, routes, firewall, version and signature."
        >
            <div className="flex items-center gap-3 border-b border-border px-5 py-4">
                <ShieldCheck className="size-4 text-status-ok" aria-hidden="true" />
                <p className="text-sm font-medium">Desired state for vps-fra-1</p>
                <span className="ml-auto type-small text-muted-foreground">verified</span>
            </div>
            <ul className="flex flex-col gap-2 px-5 py-4 font-mono text-[12.5px] text-muted-foreground">
                {rows.map((r) => (
                    <li key={r}>{r}</li>
                ))}
            </ul>
        </Panel>
    );
}
