import {
    CircleCheck,
    CircleX,
    ExternalLink,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { StepList } from '@/components/steps';
import type { StepStatus } from '@/components/steps';
import { StatusIcon } from '@/components/status';
import type { StatusState } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { requestJson } from '@/features/projects/http';
import { cn } from '@/lib/utils';

type Investigation = App.Data.Security.InvestigationDetailData;
type Line = App.Data.Security.InvestigationLineData;
type Step = App.Data.Security.InvestigationStepData;

const replicaStep: Record<string, StepStatus> = {
    pending: 'running',
    done: 'succeeded',
    failed: 'failed',
    offline: 'failed',
    unsupported: 'skipped',
};

/** A running investigation: one live step per replica (docs/22 §9.4). */
export function InvestigationProgress({
    investigation,
}: {
    investigation: Investigation;
}) {
    const steps = [
        ...investigation.replicas.map((replica, index) => ({
            id: `replica-${index}`,
            name: `Container on ${replica.server}`,
            status: replicaStep[replica.state] ?? 'pending',
            summary:
                replica.state === 'offline'
                    ? `Couldn’t check: ${replica.server} is offline`
                    : replica.state === 'unsupported'
                      ? `Couldn’t check: update ${replica.server}`
                      : (replica.message ?? undefined),
        })),
        {
            id: 'firewall',
            name: 'Web firewall',
            status: 'pending' as StepStatus,
        },
        { id: 'requests', name: 'Requests', status: 'pending' as StepStatus },
        {
            id: 'reach',
            name: 'What the service can reach',
            status: 'pending' as StepStatus,
        },
    ];

    return <StepList steps={steps} />;
}

const lineIcon = {
    concerning: {
        icon: CircleX,
        tone: 'text-status-error',
        sr: 'Concerning: ',
    },
    notable: {
        icon: TriangleAlert,
        tone: 'text-status-progress',
        sr: 'Worth a look: ',
    },
    clear: { icon: CircleCheck, tone: 'text-faint', sr: '' },
} as const;

function FoundLine({ line }: { line: Line }) {
    const spec =
        lineIcon[line.level as keyof typeof lineIcon] ?? lineIcon.clear;
    const Icon = spec.icon;

    return (
        <li className="flex items-start gap-2.5 type-body">
            <Icon
                aria-hidden="true"
                className={cn('mt-0.5 size-4 shrink-0', spec.tone)}
            />
            <span className="min-w-0 [overflow-wrap:anywhere]">
                {spec.sr && <span className="sr-only">{spec.sr}</span>}
                {line.text}
                {line.network && (
                    <span className="text-muted-foreground">
                        {' '}
                        Network{' '}
                        <code className="font-mono">{line.network}</code>.
                    </span>
                )}
            </span>
        </li>
    );
}

/**
 * The investigation report (docs/22 §7.5): what was checked, what was
 * found with a level each, what to do now, and the honesty line.
 */
export function InvestigationReport({
    investigation,
    firewallUrl,
    canChangeFirewall,
    onChanged,
}: {
    investigation: Investigation;
    firewallUrl: string;
    canChangeFirewall: boolean;
    onChanged?: () => void;
}) {
    const report = investigation.report;

    if (!report || investigation.status === 'running') {
        return (
            <div className="flex flex-col gap-3">
                <p className="type-small text-muted-foreground">
                    Started <TimeAgo value={investigation.startedAt} />
                    {investigation.startedBy
                        ? ` by ${investigation.startedBy}`
                        : ''}
                    .
                </p>
                <InvestigationProgress investigation={investigation} />
            </div>
        );
    }

    const ordered = [...report.found].sort(
        (a, b) => rank(a.level) - rank(b.level),
    );

    const concerning = report.found.filter(
        (l) => l.level === 'concerning',
    ).length;
    const notable = report.found.filter(
        (l) =>
            l.level === 'notable' &&
            l.network === null &&
            !l.text.includes('matched'),
    ).length;
    const verdict: { state: StatusState; title: string } =
        concerning > 0
            ? {
                  state: 'failed',
                  title: `${concerning} concerning ${concerning === 1 ? 'finding' : 'findings'}`,
              }
            : notable > 0
              ? {
                    state: 'warning',
                    title: `${notable} ${notable === 1 ? 'thing' : 'things'} worth a look`,
                }
              : report.partial
                ? { state: 'warning', title: 'Some parts couldn’t be checked' }
                : { state: 'active', title: 'Nothing unusual found' };

    return (
        <div className="flex flex-col gap-6">
            <div
                data-investigation-verdict={verdict.state}
                className={cn(
                    'flex items-start gap-3 rounded-lg px-4 py-3',
                    verdict.state === 'failed'
                        ? 'bg-status-error-soft'
                        : verdict.state === 'warning'
                          ? 'bg-status-progress-soft'
                          : 'bg-recessed',
                )}
            >
                <StatusIcon state={verdict.state} className="mt-[3px]" />
                <div className="flex min-w-0 flex-col">
                    <p className="type-body font-medium">{verdict.title}</p>
                    <p className="type-small text-muted-foreground">
                        {investigation.automatic
                            ? 'Checked automatically '
                            : 'Checked '}
                        <TimeAgo
                            value={
                                investigation.finishedAt ??
                                investigation.startedAt
                            }
                        />
                        {investigation.startedBy
                            ? ` by ${investigation.startedBy}`
                            : ''}
                        {report.partial && verdict.state !== 'warning'
                            ? '. Some parts couldn’t be checked.'
                            : ''}
                    </p>
                </div>
            </div>

            <div className="flex flex-col gap-2.5">
                <h4 className="type-label text-muted-foreground">
                    What we found
                </h4>
                <ul className="flex flex-col gap-2">
                    {ordered.map((line, index) => (
                        <FoundLine key={index} line={line} />
                    ))}
                </ul>
            </div>

            {report.steps.length > 0 && (
                <div className="flex flex-col gap-2.5">
                    <h4 className="type-label text-muted-foreground">
                        What to do now
                    </h4>
                    <ol className="flex flex-col divide-y rounded-xl border bg-card">
                        {report.steps.map((step, index) => (
                            <StepRow
                                key={index}
                                index={index + 1}
                                step={step}
                                firewallUrl={firewallUrl}
                                canChangeFirewall={canChangeFirewall}
                                onChanged={onChanged}
                            />
                        ))}
                    </ol>
                </div>
            )}

            <div className="flex flex-col gap-2.5">
                <h4 className="type-label text-muted-foreground">
                    What we checked
                </h4>
                <ul className="flex flex-col gap-1.5 type-small">
                    {report.checked.map((check, index) => (
                        <li key={index} className="flex items-baseline gap-2">
                            {check.state === 'checked' ? (
                                <CircleCheck
                                    aria-hidden="true"
                                    className="size-3.5 shrink-0 translate-y-0.5 text-faint"
                                />
                            ) : (
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="size-3.5 shrink-0 translate-y-0.5 text-status-progress"
                                />
                            )}
                            <span>
                                {check.label}
                                <span className="text-muted-foreground">
                                    , {check.window}
                                </span>
                                {check.note && (
                                    <span className="text-muted-foreground">
                                        . {check.note}
                                    </span>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            </div>

            <p className="rounded-lg bg-recessed px-4 py-3 type-small text-muted-foreground">
                {report.honesty}
            </p>
        </div>
    );
}

function rank(level: string): number {
    return level === 'concerning' ? 0 : level === 'notable' ? 1 : 2;
}

function StepRow({
    index,
    step,
    firewallUrl,
    canChangeFirewall,
    onChanged,
}: {
    index: number;
    step: Step;
    firewallUrl: string;
    canChangeFirewall: boolean;
    onChanged?: () => void;
}) {
    const [, copy] = useClipboard();
    const [busy, setBusy] = useState(false);

    const block = async () => {
        setBusy(true);

        try {
            await requestJson('patch', firewallUrl, { mode: 'block' });
            toast.success('The firewall blocks attacks now');
            onChanged?.();
        } catch (error) {
            toast.error((error as Error).message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <li className="flex gap-3 px-4 py-3">
            <span className="flex size-5 shrink-0 items-center justify-center rounded-full border type-small text-muted-foreground tabular-nums">
                {index}
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                <p className="type-body">{step.text}</p>
                {step.command && (
                    <div className="flex min-w-0 items-center gap-2 rounded-md bg-recessed px-3 py-2">
                        <code className="min-w-0 flex-1 truncate type-mono-small">
                            {step.command}
                        </code>
                        <Button
                            size="sm"
                            variant="ghost"
                            className="h-7 shrink-0"
                            onClick={() => {
                                void copy(step.command as string);
                                toast.success('Copied');
                            }}
                        >
                            Copy
                        </Button>
                    </div>
                )}
                {(step.links.length > 0 ||
                    (step.kind === 'block' && canChangeFirewall)) && (
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 type-small">
                        {step.kind === 'block' && canChangeFirewall && (
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={busy}
                                onClick={() => void block()}
                            >
                                Turn on blocking
                            </Button>
                        )}
                        {step.links.map((link) => (
                            <a
                                key={link.href}
                                href={link.href}
                                target={link.external ? '_blank' : undefined}
                                rel={
                                    link.external
                                        ? 'noopener noreferrer'
                                        : undefined
                                }
                                className="inline-flex items-center gap-1 text-signal hover:underline"
                            >
                                {link.label}
                                {link.external && (
                                    <ExternalLink
                                        aria-hidden="true"
                                        className="size-3"
                                    />
                                )}
                            </a>
                        ))}
                    </div>
                )}
            </div>
        </li>
    );
}
