import { Link } from '@inertiajs/react';
import {
    Check,
    Link2,
    ScrollText,
    ShieldCheck,
    TriangleAlert,
    Waypoints,
} from 'lucide-react';
import { useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { toast } from 'sonner';
import { CopyButton } from '@/components/copy-button';
import { Page, PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { TopBarActions } from '@/components/patterns/top-bar';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    allowBody,
    allowedToast,
    allowLabel,
    uncoveredNote,
} from '@/features/security/exclusion-draft';
import { useClipboard } from '@/hooks/use-clipboard';
import { requestJson } from '@/lib/http';
import { labelOf, vocabulary } from '@/lib/vocabulary';
import {
    failureWords,
    formatMicros,
    statusWords,
    targetName,
    timing,
} from './trace-model';
import type { RequestTrace } from './trace-model';
import { TracePathDiagram } from './trace-path';
import { TracePastePopover } from './trace-paste';

const exactTime = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    fractionalSecondDigits: 3,
});

function delay(ms: number): CSSProperties {
    return { '--trace-delay': `${ms}ms` } as CSSProperties;
}

/**
 * A decoded request trace (docs/23 §7): what happened in one sentence
 * with the next actions, the request's path drawn through the edge and
 * app servers to the service, the time it took, then the facts and the
 * firewall rules that matched.
 */
export function TraceView({ trace }: { trace: RequestTrace }) {
    const firewall = trace.firewall;
    const flagged = firewall !== null && firewall.outcome !== 'clean';
    const allow = flagged ? trace.allow : null;

    return (
        <Page>
            <TopBarActions>
                <CopyLink />
                <TracePastePopover>
                    <Button variant="ghost" size="sm">
                        <Waypoints aria-hidden="true" />
                        Trace another
                    </Button>
                </TracePastePopover>
            </TopBarActions>

            <PageHeader
                title={trace.summary}
                actions={
                    <>
                        {allow && <AllowRequests trace={trace} draft={allow} />}
                        {trace.logsHref && (
                            <Button
                                variant={allow ? 'outline' : 'default'}
                                asChild
                            >
                                <Link href={trace.logsHref}>
                                    <ScrollText aria-hidden="true" />
                                    Open logs
                                </Link>
                            </Button>
                        )}
                        {flagged && trace.firewallHref && (
                            <Button variant="outline" asChild>
                                <Link href={trace.firewallHref}>
                                    <ShieldCheck aria-hidden="true" />
                                    Open firewall
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />

            <section
                aria-label="Request path"
                className="flex min-w-0 flex-col overflow-hidden rounded-xl border bg-card shadow-card"
            >
                <RequestLine trace={trace} />
                <div className="border-t bg-recessed">
                    <TracePathDiagram trace={trace} />
                </div>
                <TimingBar trace={trace} />
            </section>

            <Facts trace={trace} />

            {firewall && firewall.rules.length > 0 && (
                <Section
                    id="trace-rules"
                    title={
                        firewall.rules.length === 1
                            ? 'Rule that matched'
                            : 'Rules that matched'
                    }
                    description={`Score ${firewall.score} in ${labelOf(vocabulary.Security.WafMode, firewall.mode) ?? firewall.mode} mode.`}
                    className="scroll-mt-20"
                >
                    <ul className="flex flex-col divide-y overflow-hidden rounded-xl border bg-card shadow-card">
                        {firewall.rules.map((rule) => (
                            <li
                                key={rule.id}
                                className="flex items-baseline gap-4 px-5 py-3"
                            >
                                <code className="w-16 shrink-0 type-mono text-muted-foreground tabular-nums">
                                    {rule.id}
                                </code>
                                <span className="flex min-w-0 flex-1 flex-col gap-0.5 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4">
                                    <span className="type-body">
                                        {rule.message}
                                    </span>
                                    <span className="shrink-0 type-small text-muted-foreground">
                                        {labelOf(
                                            vocabulary.Security.WafCategory,
                                            rule.category,
                                        ) ?? rule.category}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}
        </Page>
    );
}

/** `GET shop.example.com/products/:id` with when it arrived. */
function RequestLine({ trace }: { trace: RequestTrace }) {
    const target = `${trace.host ?? ''}${trace.path ?? ''}`;

    return (
        <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 px-5 py-3.5">
            <code
                className="min-w-0 truncate type-mono"
                title={`${trace.method ?? ''} ${target}`.trim()}
            >
                {trace.method && (
                    <span className="mr-2 font-medium">{trace.method}</span>
                )}
                <span className="text-muted-foreground">{target || '/'}</span>
            </code>
            <span className="type-small text-muted-foreground tabular-nums">
                <TimeAgo value={trace.receivedAt} />
            </span>
        </div>
    );
}

/**
 * Time to the response headers split into the edge server's share and the
 * app's, as one segmented bar with the numbers beside it.
 */
function TimingBar({ trace }: { trace: RequestTrace }) {
    const time = timing(trace);

    if (time === null) {
        return null;
    }

    const name = targetName(trace);
    const parts: {
        key: string;
        label: string;
        us: number;
        color: string;
    }[] = [
        {
            key: 'edge',
            label: trace.edge.name,
            us: time.edgeUs,
            color: 'var(--chart-other)',
        },
        ...(time.appUs !== null
            ? [
                  {
                      key: 'app',
                      label: name,
                      us: time.appUs,
                      color: 'var(--signal)',
                  },
              ]
            : []),
    ];

    return (
        <div className="flex flex-col gap-2.5 border-t px-5 py-4">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <span className="type-label">Time to response headers</span>
                <span className="type-mono tabular-nums">
                    {formatMicros(time.totalUs)}
                </span>
            </div>
            {parts.length > 1 && (
                <div
                    role="img"
                    aria-label={parts
                        .map((part) => `${part.label} ${formatMicros(part.us)}`)
                        .join(', ')}
                    className="flex h-2 w-full gap-0.5"
                >
                    {parts.map((part, i) => (
                        <span
                            key={part.key}
                            className="trace-grow h-full rounded-full"
                            style={{
                                flexGrow: Math.max(part.us, 1),
                                flexBasis: 0,
                                minWidth: 3,
                                backgroundColor: part.color,
                                ...delay(200 + i * 160),
                            }}
                        />
                    ))}
                </div>
            )}
            <ul className="flex flex-wrap gap-x-5 gap-y-1 type-small text-muted-foreground">
                {parts.map((part) => (
                    <li
                        key={part.key}
                        className="inline-flex items-center gap-2"
                    >
                        <span
                            aria-hidden="true"
                            className="size-2 rounded-full"
                            style={{ backgroundColor: part.color }}
                        />
                        <span>
                            {part.key === 'edge'
                                ? `On ${part.label}`
                                : trace.origin === 'upstream_failure'
                                  ? `Waiting for ${part.label}`
                                  : `Until ${part.label} answered`}
                        </span>
                        <span className="type-mono-small text-foreground tabular-nums">
                            {formatMicros(part.us)}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col gap-1">
            <dt className="type-small text-muted-foreground">{label}</dt>
            <dd className="min-w-0 type-body">{children}</dd>
        </div>
    );
}

/** The facts in a short grid (docs/23 §7). */
function Facts({ trace }: { trace: RequestTrace }) {
    const firewall = trace.firewall;
    const name = trace.service?.name ?? 'The app';
    const otherStatus =
        trace.upstreamStatus !== null && trace.upstreamStatus !== trace.status
            ? trace.upstreamStatus
            : null;

    return (
        <Section title="Details">
            <dl className="grid gap-x-8 gap-y-5 rounded-xl border bg-card px-5 py-4 shadow-card sm:grid-cols-2 lg:grid-cols-3">
                <Fact label="Received">
                    <span className="tabular-nums">
                        {exactTime.format(new Date(trace.receivedAt))}
                    </span>
                </Fact>
                <Fact label="Request id">
                    <span className="flex min-w-0 items-center gap-1">
                        <code className="min-w-0 truncate type-mono">
                            {trace.requestId}
                        </code>
                        <CopyButton
                            value={trace.requestId}
                            label="request id"
                            size="xs"
                        />
                    </span>
                </Fact>
                {trace.host && (
                    <Fact label="Domain">
                        <code className="block truncate type-mono">
                            {trace.host}
                        </code>
                    </Fact>
                )}
                {trace.protocol && (
                    <Fact label="Protocol">{trace.protocol}</Fact>
                )}
                <Fact label="Status">
                    <span className="tabular-nums">
                        {statusWords(trace.status)}
                    </span>
                    {otherStatus !== null && (
                        <span className="block type-small text-muted-foreground tabular-nums">
                            {name} answered {statusWords(otherStatus)}
                        </span>
                    )}
                </Fact>
                {trace.failure && (
                    <Fact label="Upstream">{failureWords(trace)}</Fact>
                )}
                {trace.attempts !== null &&
                    (trace.attempts > 1 || trace.failure !== null) && (
                        <Fact label="Attempts">
                            <span className="tabular-nums">
                                {trace.attempts}
                            </span>
                        </Fact>
                    )}
                {firewall && (
                    <Fact label="Firewall">
                        {firewall.mode === 'block' ? 'Block mode' : 'Log only'}
                        <span className="text-muted-foreground tabular-nums">
                            , score {firewall.score}
                        </span>
                    </Fact>
                )}
            </dl>
        </Section>
    );
}

/**
 * "Allow requests like this" (docs/23 §6): one firewall exception per
 * matched rule for this path, confirmed in a popover that says what
 * stops being checked.
 */
function AllowRequests({
    trace,
    draft,
}: {
    trace: RequestTrace;
    draft: App.Data.Security.WafExclusionDraftData;
}) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [done, setDone] = useState(false);
    const note = uncoveredNote(draft);

    const allow = async () => {
        setBusy(true);

        try {
            const result = await requestJson<{ added: string[] }>(
                'post',
                draft.allowUrl,
                allowBody(draft),
            );
            toast.success(allowedToast(draft, result.added.length));
            setDone(true);
            setOpen(false);
        } catch (error) {
            toast.error((error as Error).message);
        } finally {
            setBusy(false);
        }
    };

    if (done) {
        return (
            <Button variant="outline" disabled>
                <Check aria-hidden="true" />
                Exceptions added
            </Button>
        );
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button>Allow requests like this</Button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                className="w-[min(26rem,calc(100vw-2rem))]"
            >
                <div className="flex flex-col gap-3">
                    <p className="type-body">
                        {draft.rules.length === 1
                            ? 'This rule stops checking'
                            : 'These rules stop checking'}{' '}
                        {draft.path ? (
                            <>
                                requests under{' '}
                                <code className="type-mono">{draft.path}</code>
                            </>
                        ) : (
                            'all requests'
                        )}
                        {trace.service ? ` on ${trace.service.name}` : ''}:
                    </p>
                    <ul className="flex flex-col gap-1.5">
                        {draft.rules.map((rule) => (
                            <li
                                key={rule.rule}
                                className="flex items-baseline gap-3 type-small"
                            >
                                <code className="type-mono-small text-muted-foreground">
                                    {rule.rule}
                                </code>
                                <span>
                                    {rule.message}
                                    {rule.target && (
                                        <>
                                            {', only in '}
                                            <code className="type-mono-small">
                                                {rule.target}
                                            </code>
                                        </>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                    {note && (
                        <p className="flex items-center gap-2 type-small text-muted-foreground">
                            <TriangleAlert
                                aria-hidden="true"
                                className="size-3.5 shrink-0 text-status-progress"
                            />
                            {note}
                        </p>
                    )}
                    <div className="flex justify-end gap-2">
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            disabled={busy}
                            onClick={() => void allow()}
                        >
                            {allowLabel(draft)}
                        </Button>
                    </div>
                </div>
            </PopoverContent>
        </Popover>
    );
}

/** Copies the page's address, so a teammate can open the same trace. */
function CopyLink() {
    const [copied, copy] = useClipboard();
    const href = typeof window !== 'undefined' ? window.location.href : '';

    return (
        <Button
            variant="ghost"
            size="sm"
            onClick={() => {
                void copy(href);
            }}
        >
            {copied === href ? (
                <Check aria-hidden="true" />
            ) : (
                <Link2 aria-hidden="true" />
            )}
            Copy link
        </Button>
    );
}
