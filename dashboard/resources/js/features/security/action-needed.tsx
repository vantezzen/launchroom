import {
    ChevronDown,
    ChevronRight,
    MoreHorizontal,
    RefreshCw,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Logo } from '@/components/logo';
import { MetaLine } from '@/components/patterns/meta-line';
import { StatusIcon } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { plural } from '@/lib/format';
import { requestJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { dismissMany } from '@/routes/security/findings';
import { DismissReasonFields } from './dismiss-reason';
import { KnownExploited, SeverityText } from './finding-card';
import { card } from './format';
import {
    commonVerdict,
    fixTitle,
    splitByProduction,
    verdictOf,
    worstVerdict,
} from './shape';

type FixGroup = App.Data.Security.FixGroupData;
type FixService = App.Data.Security.FixGroupServiceData;
type Advisory = App.Data.Security.FixGroupAdvisoryData;

/** Cards shown before "Show N more" (docs/22 §9.2 part 2). */
const VISIBLE = 4;
/** Service chips on a card before "+N". */
const CHIPS = 4;
/** Advisory rows in an open card before "Show all". */
const ADVISORIES = 6;

type Handlers = {
    /** Opens a finding's sheet by its public id. */
    onOpen: (finding: string) => void;
    onInvestigate: (service: FixService) => void;
    onChanged: () => void;
    canManage: boolean;
};

/**
 * "Action needed" (docs/22 §9.2 part 2, D107): one card per thing to fix,
 * a package with every advisory and service it affects. Groups that touch
 * production come first; the ones only in other environments follow under
 * their own quiet heading. The first four show, the rest behind "Show N
 * more".
 */
export function FixGroupList({
    groups,
    ...handlers
}: { groups: FixGroup[] } & Handlers) {
    const [all, setAll] = useState(false);
    const { production, other } = splitByProduction(groups);
    const visibleProduction = all ? production : production.slice(0, VISIBLE);
    const room = Math.max(0, VISIBLE - visibleProduction.length);
    const visibleOther = all ? other : other.slice(0, room);
    const hidden =
        production.length -
        visibleProduction.length +
        (other.length - visibleOther.length);

    return (
        <div className="flex flex-col gap-3">
            {visibleProduction.map((group) => (
                <FixGroupCard key={group.key} group={group} {...handlers} />
            ))}
            {visibleOther.length > 0 && (
                <h3
                    className={cn(
                        'type-label text-muted-foreground',
                        visibleProduction.length > 0 && 'mt-3',
                    )}
                >
                    Only outside production
                </h3>
            )}
            {visibleOther.map((group) => (
                <FixGroupCard key={group.key} group={group} {...handlers} />
            ))}
            {hidden > 0 && (
                <Button
                    variant="outline"
                    className="self-start"
                    onClick={() => setAll(true)}
                >
                    Show {plural(hidden, 'more fix', 'more fixes')}
                </Button>
            )}
        </div>
    );
}

export function FixGroupCard({
    group,
    onOpen,
    onInvestigate,
    onChanged,
    canManage,
    defaultOpen = false,
}: { group: FixGroup; defaultOpen?: boolean } & Handlers) {
    const [open, setOpen] = useState(defaultOpen);
    const [dismissing, setDismissing] = useState(false);
    const worst = worstVerdict(group.services);
    const common = commonVerdict(group.services);
    const vulnerabilities = plural(
        group.advisories.length,
        'vulnerability',
        'vulnerabilities',
    );

    return (
        <article
            id={group.key}
            data-fix-group={group.key}
            className={cn(
                card,
                'flex min-w-0 scroll-mt-20 flex-col overflow-hidden',
            )}
        >
            <div className="flex min-w-0 flex-col gap-4 px-5 pt-5 pb-4 sm:flex-row sm:items-start sm:gap-6 sm:px-6">
                <div className="flex min-w-0 flex-1 flex-col gap-2">
                    <MetaLine className="type-small">
                        <SeverityText
                            severity={group.severity}
                            cvss={group.cvss}
                        />
                        {group.knownExploited ? (
                            <KnownExploited since={group.knownExploitedSince} />
                        ) : null}
                        <span className="text-muted-foreground">
                            {group.advisories.length === 1 ? (
                                <code className="type-mono-small">
                                    {group.advisories[0].id}
                                </code>
                            ) : (
                                vulnerabilities
                            )}
                        </span>
                    </MetaLine>
                    <h3 className="type-heading text-[17px] leading-6 [overflow-wrap:anywhere]">
                        {fixTitle(group)}
                    </h3>
                    <p className="type-body [overflow-wrap:anywhere] text-muted-foreground">
                        {group.impact ?? group.headline}{' '}
                        <span className="whitespace-nowrap">
                            Installed:{' '}
                            <code className="type-mono-small text-foreground">
                                {group.versions.join(', ')}
                            </code>
                        </span>
                    </p>
                    <ServiceChips group={group} onOpen={onOpen} />
                    {worst && (
                        <p className="flex items-center gap-2 type-small">
                            <StatusIcon state={worst.state} />
                            <span>
                                {worst.text}
                                {worst.more > 0 && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        and {plural(worst.more, 'more service')}
                                    </span>
                                )}
                            </span>
                        </p>
                    )}
                </div>
                <div className="flex shrink-0 items-center gap-1 self-start">
                    <Button size="sm" onClick={() => onOpen(group.lead)}>
                        See the fix
                    </Button>
                    {canManage && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    size="icon-sm"
                                    variant="ghost"
                                    data-fix-menu
                                    aria-label={`More for ${group.package}`}
                                >
                                    <MoreHorizontal aria-hidden="true" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    onSelect={() => setDismissing(true)}
                                >
                                    Not affected…
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
            </div>

            <button
                type="button"
                data-fix-toggle
                aria-expanded={open}
                onClick={() => setOpen((value) => !value)}
                className="flex w-full items-center gap-2 border-t px-5 py-2.5 text-left type-small text-muted-foreground hover:bg-hover hover:text-foreground focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-signal sm:px-6"
            >
                <ChevronDown
                    aria-hidden="true"
                    className={cn(
                        'size-4 shrink-0 transition-transform',
                        !open && '-rotate-90',
                    )}
                />
                {plural(group.services.length, 'service')} and {vulnerabilities}
            </button>

            {open && (
                <div className="border-t">
                    {common && (
                        <p className="flex items-center gap-2 border-b bg-recessed/50 px-5 py-2 type-small text-muted-foreground sm:px-6">
                            <StatusIcon state={common.state} />
                            <span>
                                {common.title}
                                {' in '}
                                {common.count === group.services.length
                                    ? `all ${group.services.length} services`
                                    : `${common.count} of ${group.services.length} services`}
                            </span>
                        </p>
                    )}
                    <ul
                        aria-label={`Services using ${group.package}`}
                        className="divide-y"
                    >
                        {group.services.map((service) => (
                            <ServiceRow
                                key={
                                    service.service.id +
                                    service.service.environmentId
                                }
                                service={service}
                                onOpen={onOpen}
                                onInvestigate={onInvestigate}
                                canInvestigate={canManage}
                                quiet={common?.title}
                            />
                        ))}
                    </ul>
                    <AdvisoryList
                        advisories={group.advisories}
                        onOpen={onOpen}
                    />
                </div>
            )}

            {canManage && (
                <DismissGroupDialog
                    open={dismissing}
                    onOpenChange={setDismissing}
                    group={group}
                    onDismissed={onChanged}
                />
            )}
        </article>
    );
}

/** "web shop", "site blog", "+3": production first, others quieter. */
function ServiceChips({
    group,
    onOpen,
}: {
    group: FixGroup;
    onOpen: (finding: string) => void;
}) {
    const shown = group.services.slice(0, CHIPS);
    const more = group.services.length - shown.length;

    return (
        <ul
            aria-label={`Affected: ${plural(group.services.length, 'service')}`}
            className="flex flex-wrap items-center gap-1.5 pt-1"
        >
            {shown.map((s) => (
                <li key={s.service.id + s.service.environmentId}>
                    <button
                        type="button"
                        onClick={() => onOpen(s.lead)}
                        className={cn(
                            'inline-flex max-w-full items-center gap-1.5 rounded-full border py-0.5 pr-2.5 pl-1 type-small hover:bg-hover focus-visible:outline-2 focus-visible:outline-signal',
                            !s.production && 'text-muted-foreground',
                        )}
                    >
                        <Logo
                            kind={s.service.logo ?? 'app'}
                            name={s.service.name}
                            size={20}
                            decorative
                        />
                        <span className="truncate font-medium">
                            {s.service.name}
                        </span>
                        <span className="truncate text-muted-foreground">
                            {s.service.projectName}
                            {s.production
                                ? ''
                                : ` ${s.service.environmentName}`}
                        </span>
                    </button>
                </li>
            ))}
            {more > 0 && (
                <li className="type-small text-muted-foreground tabular-nums">
                    +{more}
                </li>
            )}
        </ul>
    );
}

function ServiceRow({
    service: row,
    onOpen,
    onInvestigate,
    canInvestigate,
    quiet,
}: {
    service: FixService;
    onOpen: (finding: string) => void;
    onInvestigate: (service: FixService) => void;
    canInvestigate: boolean;
    /** The verdict said once above the rows; rows with it leave it out. */
    quiet?: string;
}) {
    const { service } = row;
    const verdict = verdictOf(row.investigation);
    const said = quiet === verdict.title;
    const running = row.investigation?.status === 'running';

    return (
        <li className="relative flex min-w-0 items-center gap-2 pr-3 hover:bg-hover sm:pr-4">
            <button
                type="button"
                data-finding-open={row.lead}
                onClick={() => onOpen(row.lead)}
                aria-label={`Open ${service.name} in ${service.projectName}, ${service.environmentName}`}
                className="grid min-w-0 flex-1 grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-1 py-2.5 pl-5 text-left focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-signal sm:pl-6 lg:grid-cols-[auto_minmax(0,13rem)_minmax(0,11rem)_minmax(0,1fr)]"
            >
                <Logo
                    kind={service.logo ?? 'app'}
                    name={service.name}
                    size={20}
                    decorative
                />
                <span className="flex min-w-0 flex-col">
                    <span className="truncate type-body font-medium">
                        {service.name}
                    </span>
                    <MetaLine className="type-small text-muted-foreground">
                        {service.projectName}
                        {service.environmentName}
                    </MetaLine>
                </span>
                <code className="col-start-2 truncate type-mono-small text-muted-foreground lg:col-start-auto">
                    {row.version}
                    {row.fixedVersion ? ` → ${row.fixedVersion}` : ''}
                </code>
                <span
                    className={cn(
                        'col-start-2 flex min-w-0 items-center gap-2 type-small lg:col-start-auto',
                        said && 'max-lg:hidden',
                    )}
                >
                    {!said && (
                        <>
                            <StatusIcon state={verdict.state} />
                            <span className="min-w-0 truncate">
                                {verdict.title}
                                {verdict.at && !running && (
                                    <span className="text-faint">
                                        {' '}
                                        <TimeAgo value={verdict.at} />
                                    </span>
                                )}
                            </span>
                        </>
                    )}
                </span>
            </button>
            {canInvestigate && (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            size="icon-sm"
                            variant="ghost"
                            aria-label={`Investigate ${service.name} again`}
                            disabled={running}
                            onClick={() => onInvestigate(row)}
                            className="hidden shrink-0 text-muted-foreground sm:inline-flex"
                        >
                            <RefreshCw aria-hidden="true" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Investigate again</TooltipContent>
                </Tooltip>
            )}
            <ChevronRight
                aria-hidden="true"
                className="size-4 shrink-0 text-faint"
            />
        </li>
    );
}

function AdvisoryList({
    advisories,
    onOpen,
}: {
    advisories: Advisory[];
    onOpen: (finding: string) => void;
}) {
    const [all, setAll] = useState(false);
    const shown = all ? advisories : advisories.slice(0, ADVISORIES);

    return (
        <div className="border-t bg-recessed/50">
            <ul aria-label="Vulnerabilities" className="divide-y">
                {shown.map((advisory) => (
                    <li key={advisory.id}>
                        <button
                            type="button"
                            onClick={() => onOpen(advisory.finding)}
                            className="grid w-full min-w-0 grid-cols-[5.5rem_minmax(0,1fr)_auto] items-baseline gap-x-3 py-2 pr-4 pl-5 text-left hover:bg-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-signal sm:pl-6"
                        >
                            <SeverityText
                                severity={advisory.severity}
                                className="type-small"
                            />
                            <span className="min-w-0 truncate type-small">
                                {advisory.title ?? advisory.id}
                            </span>
                            <code className="hidden type-mono-small text-muted-foreground sm:inline">
                                {advisory.id}
                            </code>
                        </button>
                    </li>
                ))}
            </ul>
            {advisories.length > shown.length && (
                <button
                    type="button"
                    onClick={() => setAll(true)}
                    className="w-full border-t px-5 py-2 text-left type-small text-muted-foreground hover:bg-hover hover:text-foreground sm:px-6"
                >
                    Show {advisories.length - shown.length} more
                </button>
            )}
        </div>
    );
}

/**
 * "Not affected…" for the whole group (docs/22 §5.5): every service is
 * ticked; untick the ones it doesn't apply to. A service that gets the
 * package later, or a new vulnerability, brings the group back.
 */
function DismissGroupDialog({
    open,
    onOpenChange,
    group,
    onDismissed,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    group: FixGroup;
    onDismissed: () => void;
}) {
    const [reason, setReason] = useState<string>('not_used');
    const [note, setNote] = useState('');
    const [except, setExcept] = useState<Set<string>>(new Set());
    const [busy, setBusy] = useState(false);
    const chosen = group.services.filter((s) => !except.has(s.lead));

    const toggle = (lead: string, on: boolean) =>
        setExcept((current) => {
            const next = new Set(current);

            if (on) {
                next.delete(lead);
            } else {
                next.add(lead);
            }

            return next;
        });

    const submit = async () => {
        setBusy(true);

        try {
            await requestJson('post', dismissMany().url, {
                findings: chosen.flatMap((s) => s.findings),
                reason,
                note: note.trim() === '' ? null : note,
            });
            toast.success(
                `Marked not affected in ${plural(chosen.length, 'service')}`,
            );
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
                    {group.package} stops showing for the services you pick. A
                    new service or vulnerability brings it back.
                </DialogDescription>
                <fieldset className="flex flex-col gap-2">
                    <legend className="mb-2 type-label">Services</legend>
                    <ul className="flex max-h-56 flex-col gap-1 overflow-y-auto">
                        {group.services.map((s) => (
                            <li key={s.lead}>
                                <label className="flex cursor-pointer items-center gap-3 rounded-md px-1 py-1.5 type-body hover:bg-hover">
                                    <Checkbox
                                        checked={!except.has(s.lead)}
                                        onCheckedChange={(value) =>
                                            toggle(s.lead, value === true)
                                        }
                                    />
                                    <span className="min-w-0 truncate">
                                        {s.service.name}
                                        <span className="text-muted-foreground">
                                            {' '}
                                            in {s.service.projectName},{' '}
                                            {s.service.environmentName}
                                        </span>
                                    </span>
                                </label>
                            </li>
                        ))}
                    </ul>
                </fieldset>
                <DismissReasonFields
                    reason={reason}
                    onReasonChange={setReason}
                    note={note}
                    onNoteChange={setNote}
                />
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="ghost">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={busy || chosen.length === 0}
                        onClick={() => void submit()}
                    >
                        Mark not affected
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
