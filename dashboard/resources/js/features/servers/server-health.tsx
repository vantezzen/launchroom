import { useState } from 'react';
import { SidePanel, SheetSection } from '@/components/patterns/sheet';
import { Section } from '@/components/patterns/section';
import { StatusIcon, StatusPill, healthCheckState } from '@/components/status';
import { Button } from '@/components/ui/button';
import { shortAge } from '@/features/servers/format';

type Health = App.Data.Servers.ServerHealthData;
type Check = App.Data.Servers.HealthCheckData;

function headline(health: Health): {
    state: 'active' | 'warning' | 'failed' | 'idle';
    label: string;
} {
    const failing = health.checks.filter((c) => c.state === 'critical').length;
    const warnings = health.checks.filter((c) => c.state === 'warning').length;

    if (health.collectedAt === null) {
        return { state: 'idle', label: 'Not reported yet' };
    }

    if (failing > 0) {
        return {
            state: 'failed',
            label: `${failing} ${failing === 1 ? 'problem' : 'problems'}`,
        };
    }

    if (warnings > 0) {
        return {
            state: 'warning',
            label: `${warnings} ${warnings === 1 ? 'warning' : 'warnings'}`,
        };
    }

    return { state: 'active', label: 'All good' };
}

function CheckRow({ check, detailed }: { check: Check; detailed: boolean }) {
    const { state } = healthCheckState[check.state];
    const showDetail = detailed || check.state !== 'ok';

    return (
        <li className="flex items-start gap-3 py-2.5">
            <StatusIcon state={state} className="mt-0.5" />
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <div className="flex items-baseline justify-between gap-3">
                    <span className="type-body">{check.label}</span>
                    <span className="shrink-0 type-small text-muted-foreground tabular-nums">
                        {check.value ?? 'Not reported'}
                    </span>
                </div>
                {showDetail && check.detail && (
                    <span className="type-small text-muted-foreground">
                        {check.detail}
                    </span>
                )}
            </div>
        </li>
    );
}

/**
 * Health checklist (direction §9.2): problems first, a handful of rows,
 * one value each; "Details" opens every check with its explanation.
 */
export function ServerHealth({ health }: { health: Health }) {
    const [open, setOpen] = useState(false);
    const { state, label } = headline(health);
    const rank = { critical: 0, warning: 1, ok: 2, unknown: 3 } as const;
    const sorted = [...health.checks].sort(
        (a, b) => rank[a.state] - rank[b.state],
    );
    const visible = sorted.slice(0, 5);

    return (
        <Section
            id="health"
            title="Health"
            actions={<StatusPill state={state} label={label} />}
        >
            <div className="flex flex-col rounded-xl border bg-card px-6 py-3 shadow-card">
                <ul className="divide-y">
                    {visible.map((check) => (
                        <CheckRow
                            key={check.key}
                            check={check}
                            detailed={false}
                        />
                    ))}
                </ul>
                <div className="flex items-center justify-between gap-3 border-t py-3 type-small text-muted-foreground">
                    <span>
                        {health.collectedAt
                            ? `Checked ${shortAge(health.collectedAt)}`
                            : 'Waiting for the first report'}
                        {health.stale && ' (out of date)'}
                    </span>
                    {health.checks.length > 0 && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setOpen(true)}
                        >
                            {health.checks.length > visible.length
                                ? `All ${health.checks.length} checks`
                                : 'Details'}
                        </Button>
                    )}
                </div>
            </div>

            <SidePanel
                open={open}
                onOpenChange={setOpen}
                title="Health"
                description={
                    health.collectedAt
                        ? `From the server’s last report, ${shortAge(health.collectedAt)}.`
                        : 'The server hasn’t reported yet.'
                }
                status={<StatusPill state={state} label={label} />}
            >
                <SheetSection title="Checks">
                    <ul className="divide-y">
                        {sorted.map((check) => (
                            <CheckRow key={check.key} check={check} detailed />
                        ))}
                    </ul>
                </SheetSection>
            </SidePanel>
        </Section>
    );
}
