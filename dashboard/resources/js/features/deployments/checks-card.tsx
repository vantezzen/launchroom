import { ArrowUpRight, ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { StatusIcon } from '@/components/status';
import type { StatusState } from '@/components/status';
import { Button } from '@/components/ui/button';
import { plural, shortSha } from '@/lib/format';
import { cn } from '@/lib/utils';
import { vocabulary } from '@/lib/vocabulary';

type Checks = App.Data.Deployments.DeploymentChecksData;
type Check = App.Data.Git.CommitCheckData;

const gateTone = vocabulary.CheckGateState;
const checkTone = vocabulary.Git.CommitCheckState;

/** Failed first, then the running ones, then the passed ones; by name within. */
const ORDER: Record<App.Enums.Git.CommitCheckState, number> = {
    failed: 0,
    pending: 1,
    passed: 2,
};

function sorted(checks: Check[]): Check[] {
    return [...checks].sort(
        (a, b) =>
            ORDER[a.state] - ORDER[b.state] || a.name.localeCompare(b.name),
    );
}

/** "14:32", the local clock time a waiting push gives up at. */
function clock(iso: string): string {
    return new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function minutesBetween(from: string, to: string): number {
    return Math.round((Date.parse(to) - Date.parse(from)) / 60_000);
}

/** The card's title and one sentence for each gate state. */
export function checksCopy(
    checks: Checks,
    commitSha: string | null,
    serving: boolean,
): { title: string; body: string } {
    const commit = shortSha(commitSha) ?? 'this commit';
    const pending = checks.checks.filter((check) => check.state === 'pending');

    switch (checks.state) {
        case 'waiting':
            if (checks.problem) {
                return {
                    title: 'Waiting for checks',
                    body: `${checks.problem} Trying again every minute.`,
                };
            }

            return checks.checks.length === 0
                ? {
                      title: 'Waiting for checks',
                      body: `No checks reported for ${commit} yet. If none appear shortly after the push, it deploys without them.`,
                  }
                : {
                      title: 'Waiting for checks',
                      body: `This deploys ${commit} once every check on it passed. ${pending.length} of ${plural(checks.checks.length, 'check')} still running.`,
                  };
        case 'failed':
            return {
                title: 'Not deployed: checks failed',
                body: serving
                    ? 'The previous version keeps serving. Push a fix and it deploys once its checks pass.'
                    : 'Push a fix and it deploys once its checks pass.',
            };
        case 'timed_out':
            return {
                title: 'Not deployed: checks took too long',
                body: checks.problem
                    ? `launchroom couldn’t read the checks of ${commit} for ${minutesBetween(checks.since, checks.deadline)} minutes. ${checks.problem}`
                    : `Checks were still running on ${commit} after ${minutesBetween(checks.since, checks.deadline)} minutes. Redeploy to deploy it anyway.`,
            };
        case 'passed':
            return {
                title: 'Checks passed',
                body: `Every check on ${commit} passed before it deployed.`,
            };
        case 'none':
            return {
                title: 'No checks reported',
                body: `No check appeared for ${commit}, so it deployed without waiting.`,
            };
        case 'unsupported':
            return {
                title: 'No checks on this git server',
                body: 'This repository’s git server reports no checks, so it deployed without waiting.',
            };
        case 'skipped':
            return {
                title: 'Deployed without waiting for checks',
                body: checks.skippedBy
                    ? `${checks.skippedBy} chose Deploy now.`
                    : 'Someone chose Deploy now.',
            };
    }
}

/**
 * Deploy after checks pass on the deployment page (D111, docs/06 §2.5):
 * while a push waits, what it waits for (one row per check with its state
 * and a link to it), when it gives up and Deploy now; afterwards, how the
 * gate ended. A gate that let the deployment through folds its rows away.
 */
export function ChecksCard({
    checks,
    commitSha,
    serving = false,
    canSkip = false,
    onSkip,
}: {
    checks: Checks;
    commitSha: string | null;
    /** The previous version is still live ("keeps serving"). */
    serving?: boolean;
    canSkip?: boolean;
    onSkip?: () => void;
}) {
    const settledWell = ['passed', 'none', 'unsupported', 'skipped'].includes(
        checks.state,
    );
    const [open, setOpen] = useState(!settledWell);
    const tone: StatusState = gateTone[checks.state].tone;
    const { title, body } = checksCopy(checks, commitSha, serving);
    const rows = sorted(checks.checks);

    return (
        <section
            aria-labelledby="checks-title"
            className="flex gap-4 rounded-[14px] border bg-card p-5 shadow-card"
        >
            <span
                className={cn(
                    'flex size-9 shrink-0 items-center justify-center rounded-full max-sm:hidden',
                    tone === 'failed'
                        ? 'bg-status-error-soft'
                        : tone === 'active'
                          ? 'bg-status-ok-soft'
                          : tone === 'idle'
                            ? 'bg-hover'
                            : 'bg-status-progress-soft',
                )}
            >
                <StatusIcon state={tone} className="size-4" />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-3">
                <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <h2
                        id="checks-title"
                        className="flex items-center gap-2 type-heading"
                    >
                        <StatusIcon state={tone} className="sm:hidden" />
                        {title}
                    </h2>
                    {checks.state === 'waiting' && (
                        <span className="type-small text-muted-foreground tabular-nums">
                            Gives up at {clock(checks.deadline)}
                        </span>
                    )}
                </div>
                <p className="max-w-[72ch] type-body text-muted-foreground">
                    {body}
                </p>
                {rows.length > 0 && settledWell && (
                    <button
                        type="button"
                        aria-expanded={open}
                        onClick={() => setOpen(!open)}
                        className="inline-flex w-fit items-center gap-1 rounded-sm type-label text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {open ? 'Hide' : 'Show'} {plural(rows.length, 'check')}
                        <ChevronDown
                            aria-hidden="true"
                            className={cn(
                                'size-3.5 transition-transform',
                                open && 'rotate-180',
                            )}
                        />
                    </button>
                )}
                {rows.length > 0 && open && (
                    <ul className="divide-y overflow-hidden rounded-lg border bg-recessed">
                        {rows.map((check) => (
                            <CheckRow key={check.name} check={check} />
                        ))}
                    </ul>
                )}
                {checks.state === 'waiting' && canSkip && onSkip && (
                    <div className="flex flex-wrap items-center gap-2 pt-1">
                        <ConfirmPopover
                            title="Deploy without waiting for checks?"
                            consequence="It starts now, whatever the running checks report. The choice is recorded in the audit log."
                            confirmLabel="Deploy now"
                            onConfirm={onSkip}
                        >
                            <Button size="sm" variant="outline">
                                Deploy now
                            </Button>
                        </ConfirmPopover>
                    </div>
                )}
            </div>
        </section>
    );
}

function CheckRow({ check }: { check: Check }) {
    const state = checkTone[check.state];
    const name = (
        <span className="min-w-0 truncate type-mono-small text-foreground">
            {check.name}
        </span>
    );

    return (
        <li className="flex min-h-10 items-center gap-3 px-3 py-2">
            <StatusIcon state={state.tone} />
            {check.url ? (
                <a
                    href={check.url}
                    target="_blank"
                    rel="noreferrer"
                    className="group flex min-w-0 flex-1 items-center gap-1 rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    {name}
                    <ArrowUpRight
                        aria-hidden="true"
                        className="size-3.5 shrink-0 text-muted-foreground group-hover:text-foreground"
                    />
                    <span className="sr-only">(opens the check)</span>
                </a>
            ) : (
                <span className="flex min-w-0 flex-1">{name}</span>
            )}
            <span
                className={cn(
                    'shrink-0 type-small',
                    check.state === 'failed'
                        ? 'text-status-error'
                        : 'text-muted-foreground',
                )}
            >
                {state.label}
            </span>
        </li>
    );
}
