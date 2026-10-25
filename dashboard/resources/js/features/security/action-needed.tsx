import { ArrowRight, ChevronRight, RefreshCw } from 'lucide-react';
import { Logo } from '@/components/logo';
import { MetaLine } from '@/components/patterns/meta-line';
import { StatusIcon } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { SeverityLine } from './finding-card';
import { card, plural } from './format';
import { verdictOf } from './shape';
import type { ActionGroup } from './shape';

type Finding = App.Data.Security.FindingCardData;

/**
 * "Action needed" (docs/22 §9.2 part 2): one card per vulnerability with
 * the fix and every affected service with its investigation verdict.
 */
export function ActionNeededList({
    groups,
    onOpen,
    onInvestigate,
    canInvestigate,
}: {
    groups: ActionGroup[];
    onOpen: (finding: Finding) => void;
    onInvestigate: (finding: Finding) => void;
    canInvestigate: boolean;
}) {
    return (
        <div className="flex flex-col gap-4">
            {groups.map((group) => (
                <GroupCard
                    key={group.key}
                    group={group}
                    onOpen={onOpen}
                    onInvestigate={onInvestigate}
                    canInvestigate={canInvestigate}
                />
            ))}
        </div>
    );
}

function GroupCard({
    group,
    onOpen,
    onInvestigate,
    canInvestigate,
}: {
    group: ActionGroup;
    onOpen: (finding: Finding) => void;
    onInvestigate: (finding: Finding) => void;
    canInvestigate: boolean;
}) {
    // The fix sheet opens on the service the investigation worries about most.
    const lead =
        group.findings.find(
            (f) => verdictOf(f.investigation).state === 'failed',
        ) ?? group.findings[0];

    return (
        <article
            data-action-group={group.advisory}
            className={cn(card, 'flex min-w-0 flex-col overflow-hidden')}
        >
            <header className="flex min-w-0 flex-col gap-4 px-5 pt-5 pb-4 sm:flex-row sm:items-start sm:gap-6 sm:px-6">
                <div className="flex min-w-0 flex-1 flex-col gap-2">
                    <SeverityLine
                        severity={group.severity}
                        cvss={group.cvss}
                        knownExploited={group.knownExploited}
                        knownExploitedSince={group.knownExploitedSince}
                        advisory={group.advisory}
                    />
                    <h3 className="type-heading text-[17px] leading-6 [overflow-wrap:anywhere]">
                        {group.title}
                    </h3>
                    <FixLine group={group} />
                </div>
                <Button
                    size="sm"
                    className="self-start"
                    onClick={() => onOpen(lead)}
                >
                    See the fix
                </Button>
            </header>

            <ul
                aria-label={`Affected services: ${plural(group.findings.length, 'service')}`}
                className="divide-y border-t"
            >
                {group.findings.map((finding) => (
                    <ServiceRow
                        key={finding.id}
                        finding={finding}
                        onOpen={onOpen}
                        onInvestigate={onInvestigate}
                        canInvestigate={canInvestigate}
                    />
                ))}
            </ul>
        </article>
    );
}

/** "Update next 14.1.0 → 14.2.25" with the installed versions. */
function FixLine({ group }: { group: ActionGroup }) {
    return (
        <p className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 type-mono">
            <span className="min-w-0 truncate">
                {group.package}{' '}
                <span className="text-muted-foreground">
                    {group.versions.join(', ')}
                </span>
            </span>
            {group.fixedVersion && (
                <>
                    <ArrowRight
                        aria-label="fixed in"
                        className="size-3.5 shrink-0 text-faint"
                    />
                    <span>{group.fixedVersion}</span>
                </>
            )}
        </p>
    );
}

function ServiceRow({
    finding,
    onOpen,
    onInvestigate,
    canInvestigate,
}: {
    finding: Finding;
    onOpen: (finding: Finding) => void;
    onInvestigate: (finding: Finding) => void;
    canInvestigate: boolean;
}) {
    const { service } = finding;
    const verdict = verdictOf(finding.investigation);
    const running = finding.investigation?.status === 'running';

    return (
        <li className="relative flex min-w-0 items-center gap-2 pr-3 hover:bg-hover sm:pr-4">
            <button
                type="button"
                data-finding-open={finding.id}
                onClick={() => onOpen(finding)}
                aria-label={`Open ${finding.title ?? finding.advisory} in ${service.name}, ${service.environmentName}`}
                className="grid min-w-0 flex-1 grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-1.5 py-3 pl-5 text-left focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-signal sm:pl-6 lg:grid-cols-[auto_minmax(0,14rem)_minmax(0,1fr)]"
            >
                <Logo
                    kind={service.logo ?? 'app'}
                    name={service.name}
                    size={32}
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
                <span className="col-start-2 flex min-w-0 items-start gap-2 lg:col-start-auto">
                    <StatusIcon state={verdict.state} className="mt-[3px]" />
                    <span className="flex min-w-0 flex-col">
                        <span className="type-body">
                            {verdict.title}
                            {verdict.at && !running && (
                                <span className="text-faint">
                                    {' '}
                                    <TimeAgo value={verdict.at} />
                                </span>
                            )}
                        </span>
                        {verdict.detail && (
                            <span className="type-small text-muted-foreground lg:truncate">
                                {verdict.detail}
                            </span>
                        )}
                    </span>
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
                            onClick={() => onInvestigate(finding)}
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
