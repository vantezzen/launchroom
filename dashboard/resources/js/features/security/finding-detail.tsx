import { Link } from '@inertiajs/react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import {
    CircleCheck,
    CircleX,
    ExternalLink,
    MoreHorizontal,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { MetaLine } from '@/components/patterns/meta-line';
import { SheetSection, SidePanel } from '@/components/patterns/sheet';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Textarea } from '@/components/ui/textarea';
import { requestJson } from '@/features/projects/http';
import { cn } from '@/lib/utils';
import {
    data as findingData,
    dismiss as dismissRoute,
    investigate as investigateRoute,
    reopen as reopenRoute,
} from '@/routes/security/findings';
import { SeverityLine } from './finding-card';
import { ECOSYSTEM_LABEL, longDate, SCOPE_LABEL } from './format';
import { InvestigationReport } from './investigation-report';

type Detail = App.Data.Security.FindingDetailData;

export function useFindingDetail(id: string | null, initial?: Detail) {
    return useQuery({
        queryKey: ['security-finding', id],
        queryFn: ({ signal }) =>
            requestJson<Detail>(
                'get',
                findingData(id as string).url,
                undefined,
                signal,
            ),
        enabled: id !== null,
        initialData: initial,
        refetchInterval: (query) =>
            query.state.data?.investigation?.status === 'running'
                ? 3000
                : false,
    });
}

/** Plain text as paragraphs; https links only, never HTML (docs/22 §9.3). */
function PlainText({ text }: { text: string }) {
    return (
        <div className="flex flex-col gap-3 type-body [overflow-wrap:anywhere] text-muted-foreground">
            {text
                .split(/\n{2,}/)
                .slice(0, 40)
                .map((paragraph, index) => (
                    <p key={index}>
                        {paragraph
                            .split(/(https:\/\/[^\s<>"'`)]+)/g)
                            .map((part, i) =>
                                /^https:\/\/[^\s<>"'`)]+$/.test(part) ? (
                                    <a
                                        key={i}
                                        href={part}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-signal hover:underline"
                                    >
                                        {part}
                                    </a>
                                ) : (
                                    part
                                ),
                            )}
                    </p>
                ))}
        </div>
    );
}

function Fact({
    label,
    children,
    wide,
}: {
    label: string;
    children: ReactNode;
    wide?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex min-w-0 flex-col gap-0.5',
                wide && 'sm:col-span-2',
            )}
        >
            <dt className="type-small text-muted-foreground">{label}</dt>
            <dd className="min-w-0 type-body [overflow-wrap:anywhere]">
                {children}
            </dd>
        </div>
    );
}

/**
 * A finding (docs/22 §9.3): the fix and the facts, why it is or isn't
 * urgent as a checklist, the investigation with its next steps, the
 * advisory.
 */
export function FindingDetailBody({
    detail,
    onChanged,
    menu,
}: {
    detail: Detail;
    onChanged: () => void;
    /** Shown at the end of the severity row (the sheet has no room in its header). */
    menu?: ReactNode;
}) {
    const finding = detail.finding;
    const urgent = finding.level === 'action_needed';
    const [busy, setBusy] = useState(false);

    const investigate = async () => {
        setBusy(true);

        try {
            await requestJson('post', investigateRoute(finding.id).url);
            toast.success(`Checking ${finding.service.name}`);
            onChanged();
        } catch (error) {
            toast.error((error as Error).message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <SheetSection>
                <div className="flex flex-col gap-5">
                    <div className="flex min-h-8 items-center gap-3">
                        <SeverityLine
                            severity={finding.severity}
                            cvss={finding.cvss}
                            knownExploited={finding.knownExploited}
                            knownExploitedSince={finding.knownExploitedSince}
                            className="type-body"
                        />
                        {finding.status === 'dismissed' && (
                            <span className="type-body text-muted-foreground">
                                Marked not affected
                            </span>
                        )}
                        {menu && <div className="ml-auto">{menu}</div>}
                    </div>

                    <div className="grid grid-cols-2 overflow-hidden rounded-lg bg-recessed sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                        <div className="flex min-w-0 flex-col gap-0.5 px-4 py-3">
                            <span className="type-small text-muted-foreground">
                                Installed
                            </span>
                            <code className="truncate type-mono">
                                {finding.package}{' '}
                                <span className="text-muted-foreground">
                                    {finding.version}
                                </span>
                            </code>
                        </div>
                        <div className="flex min-w-0 flex-col gap-0.5 border-l px-4 py-3">
                            <span className="type-small text-muted-foreground">
                                Fixed in
                            </span>
                            <code className="truncate type-mono">
                                {finding.fixedVersion ?? (
                                    <span className="font-sans text-muted-foreground">
                                        No fix yet
                                    </span>
                                )}
                            </code>
                        </div>
                        <div className="col-span-2 flex min-w-0 flex-col gap-0.5 border-t px-4 py-3 sm:col-span-1 sm:border-t-0 sm:border-l">
                            <span className="type-small text-muted-foreground">
                                First seen
                            </span>
                            <span className="type-body">
                                <TimeAgo value={finding.firstSeenAt} />
                            </span>
                        </div>
                    </div>

                    <dl className="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                        <Fact label="Service">
                            <span className="flex flex-wrap items-baseline gap-x-2">
                                <Link
                                    href={finding.service.href}
                                    className="font-medium hover:underline"
                                >
                                    {finding.service.name}
                                </Link>
                                <MetaLine className="type-small text-muted-foreground">
                                    {finding.service.projectName}
                                    {finding.service.environmentName}
                                </MetaLine>
                            </span>
                        </Fact>
                        <Fact label="Public on">
                            {detail.domains.length > 0 ? (
                                <span className="type-mono font-mono">
                                    {detail.domains.join(', ')}
                                </span>
                            ) : (
                                <span className="text-muted-foreground">
                                    No public domain
                                </span>
                            )}
                        </Fact>
                        <Fact label="Scope">
                            {SCOPE_LABEL[detail.scope] ?? detail.scope}
                            {detail.presence === 'bundled'
                                ? ', bundled'
                                : ', installed'}
                            {detail.direct ? ', direct dependency' : ''}
                            {detail.lockfile && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    ({detail.lockfile})
                                </span>
                            )}
                        </Fact>
                        <Fact label="Ecosystem">
                            {ECOSYSTEM_LABEL[finding.ecosystem] ??
                                finding.ecosystem}
                        </Fact>
                        {detail.otherServices.length > 0 && (
                            <Fact label="Also in" wide>
                                {detail.otherServices
                                    .map(
                                        (s) =>
                                            `${s.name} (${s.environmentName})`,
                                    )
                                    .join(', ')}
                            </Fact>
                        )}
                    </dl>
                </div>
            </SheetSection>

            <SheetSection
                title={urgent ? 'Why it needs action' : 'Why it isn’t urgent'}
            >
                <ul className="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
                    {detail.checks.map((check) => (
                        <li
                            key={check.rule}
                            className="flex items-start gap-2.5 type-body"
                        >
                            {check.passed ? (
                                <CircleCheck
                                    aria-hidden="true"
                                    className={cn(
                                        'mt-0.5 size-4 shrink-0',
                                        urgent
                                            ? 'text-foreground'
                                            : 'text-muted-foreground',
                                    )}
                                />
                            ) : (
                                <CircleX
                                    aria-hidden="true"
                                    className="mt-0.5 size-4 shrink-0 text-faint"
                                />
                            )}
                            <span>
                                <span className="sr-only">
                                    {check.passed
                                        ? 'Applies: '
                                        : 'Does not apply: '}
                                </span>
                                {check.label}
                            </span>
                        </li>
                    ))}
                </ul>
                {finding.status === 'dismissed' && detail.dismissedReason && (
                    <p className="mt-3 rounded-lg bg-recessed px-3 py-2 type-small text-muted-foreground">
                        Marked not affected
                        {detail.dismissedBy
                            ? ` by ${detail.dismissedBy}`
                            : ''}:{' '}
                        {detail.dismissedReason}.
                        {detail.dismissedNote ? ` ${detail.dismissedNote}` : ''}
                    </p>
                )}
            </SheetSection>

            <SheetSection
                title="Investigation"
                control={
                    detail.canInvestigate &&
                    detail.investigation?.status !== 'running' ? (
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={busy}
                            onClick={() => void investigate()}
                        >
                            {detail.investigation
                                ? 'Investigate again'
                                : 'Investigate'}
                        </Button>
                    ) : undefined
                }
            >
                {detail.investigation ? (
                    <InvestigationReport
                        investigation={detail.investigation}
                        firewallUrl={detail.firewallUrl}
                        canChangeFirewall={detail.canChangeFirewall}
                        onChanged={onChanged}
                    />
                ) : (
                    <p className="type-small text-muted-foreground">
                        An investigation reads the containers, the firewall’s
                        results and the requests of the last 7 days.
                    </p>
                )}
            </SheetSection>

            {detail.advisory && (
                <SheetSection title="Advisory">
                    <div className="flex flex-col gap-4">
                        <MetaLine className="type-small text-muted-foreground">
                            {detail.advisory.ids.slice(0, 4).map((id) => (
                                <code key={id} className="font-mono">
                                    {id}
                                </code>
                            ))}
                            {detail.advisory.published
                                ? `Published ${longDate(detail.advisory.published)}`
                                : null}
                        </MetaLine>
                        {detail.advisory.details && (
                            <PlainText text={detail.advisory.details} />
                        )}
                        {(detail.advisory.cvssV3 || detail.advisory.cvssV4) && (
                            <code className="type-mono-small break-all text-muted-foreground">
                                {detail.advisory.cvssV3 ??
                                    detail.advisory.cvssV4}
                            </code>
                        )}
                        {detail.advisory.references.length > 0 && (
                            <ul className="flex flex-col gap-1.5 type-small">
                                {detail.advisory.references
                                    .slice(0, 8)
                                    .map((reference) => (
                                        <li key={reference.href}>
                                            <a
                                                href={reference.href}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1 text-signal hover:underline"
                                            >
                                                {reference.label}
                                                <ExternalLink
                                                    aria-hidden="true"
                                                    className="size-3"
                                                />
                                            </a>
                                        </li>
                                    ))}
                            </ul>
                        )}
                        <p className="type-small text-faint">
                            {detail.advisory.source}
                        </p>
                    </div>
                </SheetSection>
            )}
        </>
    );
}

/** "Not affected…" and "Reopen" (docs/22 §5.5). */
export function FindingMenu({
    detail,
    onChanged,
}: {
    detail: Detail;
    onChanged: () => void;
}) {
    const [open, setOpen] = useState(false);

    if (!detail.canDismiss) {
        return null;
    }

    const reopen = async () => {
        try {
            await requestJson('post', reopenRoute(detail.finding.id).url);
            toast.success('Reopened');
            onChanged();
        } catch (error) {
            toast.error((error as Error).message);
        }
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        size="icon-sm"
                        variant="ghost"
                        aria-label="More actions"
                    >
                        <MoreHorizontal aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    {detail.finding.status === 'dismissed' ? (
                        <DropdownMenuItem onSelect={() => void reopen()}>
                            Reopen
                        </DropdownMenuItem>
                    ) : (
                        <DropdownMenuItem onSelect={() => setOpen(true)}>
                            Not affected…
                        </DropdownMenuItem>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
            <DismissDialog
                open={open}
                onOpenChange={setOpen}
                detail={detail}
                onDismissed={onChanged}
            />
        </>
    );
}

const REASONS = [
    { value: 'not_used', label: 'We don’t use the affected code' },
    { value: 'mitigated', label: 'Mitigated another way' },
    { value: 'accepted', label: 'Accepted risk' },
] as const;

function DismissDialog({
    open,
    onOpenChange,
    detail,
    onDismissed,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    detail: Detail;
    onDismissed: () => void;
}) {
    const [reason, setReason] = useState<string>('not_used');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    const submit = async () => {
        setBusy(true);

        try {
            await requestJson('post', dismissRoute(detail.finding.id).url, {
                reason,
                note: note.trim() === '' ? null : note,
            });
            toast.success('Marked not affected');
            onOpenChange(false);
            onDismissed();
        } catch (error) {
            toast.error((error as Error).message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogTitle>Not affected</DialogTitle>
                <DialogDescription>
                    {detail.finding.service.name} stops showing{' '}
                    {detail.finding.advisory} until it gets more urgent.
                </DialogDescription>
                <fieldset className="flex flex-col gap-2">
                    <legend className="mb-2 type-label">Why</legend>
                    {REASONS.map((option) => (
                        <label
                            key={option.value}
                            className={cn(
                                'flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 type-body',
                                reason === option.value
                                    ? 'border-foreground'
                                    : 'hover:border-border-strong',
                            )}
                        >
                            <input
                                type="radio"
                                name="reason"
                                value={option.value}
                                checked={reason === option.value}
                                onChange={() => setReason(option.value)}
                                className="accent-foreground"
                            />
                            {option.label}
                        </label>
                    ))}
                </fieldset>
                <label className="flex flex-col gap-2">
                    <span className="type-label">Note (optional)</span>
                    <Textarea
                        value={note}
                        maxLength={500}
                        rows={3}
                        onChange={(event) => setNote(event.target.value)}
                    />
                </label>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="ghost">Cancel</Button>
                    </DialogClose>
                    <Button disabled={busy} onClick={() => void submit()}>
                        Mark not affected
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** The finding as a wide sheet over the Security page. */
export function FindingSheet({
    id,
    onOpenChange,
}: {
    id: string | null;
    onOpenChange: (open: boolean) => void;
}) {
    const client = useQueryClient();
    const { data } = useFindingDetail(id);
    const refresh = () => {
        void client.invalidateQueries({ queryKey: ['security-finding', id] });
        void client.invalidateQueries({ queryKey: ['security'] });
    };

    return (
        <SidePanel
            open={id !== null}
            onOpenChange={onOpenChange}
            size="lg"
            title={
                data ? (data.finding.title ?? data.finding.advisory) : 'Finding'
            }
            description={data ? data.finding.advisory : undefined}
        >
            {data ? (
                <FindingDetailBody
                    detail={data}
                    onChanged={refresh}
                    menu={<FindingMenu detail={data} onChanged={refresh} />}
                />
            ) : (
                <div
                    aria-hidden="true"
                    className="m-5 h-40 animate-skeleton-in rounded-xl bg-recessed"
                />
            )}
        </SidePanel>
    );
}
