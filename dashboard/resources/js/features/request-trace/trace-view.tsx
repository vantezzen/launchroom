import { Link } from '@inertiajs/react';
import {
    Ban,
    Check,
    CodeXml,
    Coffee,
    Cookie,
    Database,
    FileCode,
    FileWarning,
    Link2,
    Network,
    ScanSearch,
    ScrollText,
    Shield,
    ShieldCheck,
    Syringe,
    Terminal,
    TriangleAlert,
    Waypoints,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { CopyButton } from '@/components/copy-button';
import { MetaLine } from '@/components/patterns/meta-line';
import { Page, PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { TopBarActions } from '@/components/patterns/top-bar';
import { StatusPill } from '@/components/status';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    allowBody,
    allowedToast,
    allowLabel,
    uncoveredNote,
} from '@/features/security/exclusion-draft';
import { useClipboard } from '@/hooks/use-clipboard';
import { useNow } from '@/hooks/use-now';
import { relativeTime } from '@/lib/format';
import { requestJson } from '@/lib/http';
import { labelOf, vocabulary } from '@/lib/vocabulary';
import { statusState, statusWords } from './trace-model';
import type { RequestTrace } from './trace-model';
import { DecisionIcon, TraceCanvas } from './trace-path';
import { TracePastePopover } from './trace-paste';
import { TtfbBar } from './trace-ttfb';

const exactTime = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    fractionalSecondDigits: 3,
});

const CATEGORY_ICON: Record<App.Enums.Security.WafCategory, LucideIcon> = {
    method: Ban,
    scanner: ScanSearch,
    protocol: Network,
    lfi: FileWarning,
    rfi: Link2,
    rce: Terminal,
    php: FileCode,
    generic: Syringe,
    xss: CodeXml,
    sqli: Database,
    session_fixation: Cookie,
    java: Coffee,
    other: Shield,
};

/**
 * A decoded ray (docs/23 §7): which request it was and what happened to
 * it, its path as cards on a canvas with the time to first byte under
 * it, then the firewall rules that matched.
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
                        Paste another ray
                    </Button>
                </TracePastePopover>
            </TopBarActions>

            <PageHeader
                title="Ray"
                className="sm:items-start"
                actions={
                    <>
                        {trace.logsHref && (
                            <Button
                                variant={flagged ? 'outline' : 'default'}
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
            >
                <div className="mt-2 flex min-w-0 flex-col gap-2">
                    <Identity trace={trace} />
                    <p className="type-body text-muted-foreground">
                        {trace.summary}
                    </p>
                </div>
            </PageHeader>

            <TracePathCard trace={trace} />

            {firewall && firewall.rules.length > 0 && (
                <Section
                    id="trace-rules"
                    title={
                        <span className="inline-flex items-center gap-2">
                            <DecisionIcon
                                outcome={firewall.outcome}
                                className="size-4"
                            />
                            {firewall.rules.length === 1
                                ? 'Rule that matched'
                                : 'Rules that matched'}
                        </span>
                    }
                    description={`Score ${firewall.score} in ${firewall.mode === 'log' ? 'Log' : 'Block'} mode.`}
                    actions={
                        allow && <AllowRequests trace={trace} draft={allow} />
                    }
                    className="scroll-mt-20"
                >
                    <ul className="flex flex-col divide-y overflow-hidden rounded-xl border bg-card shadow-card">
                        {firewall.rules.map((rule) => {
                            const category =
                                rule.category as App.Enums.Security.WafCategory;
                            const Icon = CATEGORY_ICON[category] ?? Shield;

                            return (
                                <li
                                    key={rule.id}
                                    className="flex flex-col gap-1.5 px-5 py-3.5 sm:flex-row sm:items-center sm:gap-4"
                                >
                                    <code className="w-14 shrink-0 type-mono-small text-muted-foreground tabular-nums">
                                        {rule.id}
                                    </code>
                                    <span className="min-w-0 flex-1 type-body">
                                        {rule.message}
                                    </span>
                                    <span className="inline-flex h-6 shrink-0 items-center gap-1.5 self-start rounded-full border px-2 type-small text-muted-foreground sm:self-auto">
                                        <Icon
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                        {labelOf(
                                            vocabulary.Security.WafCategory,
                                            rule.category,
                                        ) ?? rule.category}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                </Section>
            )}
        </Page>
    );
}

/** The path on its dotted canvas with the time to first byte under it. */
export function TracePathCard({ trace }: { trace: RequestTrace }) {
    return (
        <section
            aria-label="Request path"
            className="flex min-w-0 flex-col overflow-hidden rounded-xl border bg-card shadow-card"
        >
            <div className="trace-dots bg-recessed">
                <TraceCanvas trace={trace} />
            </div>
            <div className="border-t">
                <TtfbBar trace={trace} />
            </div>
        </section>
    );
}

/**
 * Which request this was: the status the visitor got, the method and
 * address, when it arrived and its request id (to the logs).
 */
function Identity({ trace }: { trace: RequestTrace }) {
    const target = `${trace.host ?? ''}${trace.path ?? ''}`;
    const words = statusWords(trace.status) ?? String(trace.status);

    return (
        <div className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2">
            <StatusPill
                state={statusState(trace.requestClass)}
                label={words}
                className="tabular-nums"
            />
            <code
                className="min-w-0 truncate type-mono"
                title={`${trace.method ?? ''} ${target}`.trim()}
            >
                {trace.method && (
                    <span className="mr-2 font-medium">{trace.method}</span>
                )}
                <span className="text-muted-foreground">{target || '/'}</span>
            </code>
            <MetaLine className="type-small text-muted-foreground">
                <Received value={trace.receivedAt} />
                <RequestId trace={trace} />
            </MetaLine>
        </div>
    );
}

/** "39 seconds ago", the exact time to the millisecond in a tooltip. */
function Received({ value }: { value: string }) {
    const recent = Math.abs(Date.now() - new Date(value).getTime()) < 60_000;
    useNow(true, recent ? 1000 : 60_000);

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <time
                    dateTime={value}
                    tabIndex={0}
                    className="rounded-sm tabular-nums outline-none focus-visible:ring-2 focus-visible:ring-signal"
                >
                    {relativeTime(value)}
                </time>
            </TooltipTrigger>
            <TooltipContent>
                Received {exactTime.format(new Date(value))}
            </TooltipContent>
        </Tooltip>
    );
}

/** The request id, short, to the logs filtered by it, with copy. */
function RequestId({ trace }: { trace: RequestTrace }) {
    const short = trace.requestId.slice(0, 8);
    const label = (
        <>
            <span className="sr-only">Request id </span>
            <span className="type-mono-small">{short}</span>
        </>
    );

    return (
        <span className="inline-flex items-center gap-0.5">
            {trace.logsHref ? (
                <Link
                    href={trace.logsHref}
                    title={`Request ${trace.requestId}: open its logs`}
                    className="rounded-sm text-foreground underline decoration-border-strong underline-offset-2 outline-none hover:decoration-foreground focus-visible:ring-2 focus-visible:ring-signal"
                >
                    {label}
                </Link>
            ) : (
                <span title={trace.requestId} className="text-foreground">
                    {label}
                </span>
            )}
            <CopyButton value={trace.requestId} label="request id" size="xs" />
        </span>
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
