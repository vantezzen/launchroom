import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { ServiceGlyph } from '@/components/service-glyph';
import { Status, StatusIcon } from '@/components/status';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useNow } from '@/hooks/use-now';
import { cn } from '@/lib/utils';
import { vocabulary } from '@/lib/vocabulary';
import services from '@/routes/projects/services';
import type { ServiceLiveStatus } from './live-status';
import { problems, statusDetail } from './live-status';
import { runRemedy } from './remedy';
import { useLiveStatus } from './store';

type NodeStatus = App.Data.Canvas.NodeStatusData;

/**
 * "4 services need attention" in the project top bar (D108, docs/08 §23):
 * a button beside the environment switcher whose popover names each
 * affected service, why, and offers its fix right there: the remedy
 * (Start, Restart, Retry deploy; deploy permission) and the place that
 * explains it (View logs, See why, Open servers).
 */
export function AttentionPopover({
    project,
    environment,
    status,
    canDeploy,
    className,
    defaultOpen = false,
}: {
    project: string;
    environment: string;
    /** The environment's rolled-up status (`health.status`). */
    status: NodeStatus;
    canDeploy: boolean;
    className?: string;
    /** Open on first render (the design gallery). */
    defaultOpen?: boolean;
}) {
    const [open, setOpen] = useState(defaultOpen);
    const items = problems(useLiveStatus(environment));

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className={cn(
                        'min-w-0 gap-1.5 px-2 type-small font-normal text-muted-foreground hover:text-foreground',
                        className,
                    )}
                    aria-label={`${status.label}: show which`}
                    data-test="environment-status"
                >
                    <StatusIcon state={status.state} className="size-3" />
                    <span className="hidden truncate @5xl:inline">
                        {status.label}
                    </span>
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-[min(30rem,calc(100vw-2rem))] p-0"
                // The list, not its first button, takes focus: no ring on a
                // fix the user hasn't reached yet.
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    (event.currentTarget as HTMLElement | null)?.focus();
                }}
            >
                <p className="border-b px-4 py-3 type-label">{status.label}</p>
                {items.length === 0 ? (
                    <p className="px-4 py-3 type-small text-muted-foreground">
                        Checking what changed…
                    </p>
                ) : (
                    <ul className="max-h-[min(24rem,60vh)] overflow-y-auto py-1">
                        {items.map((item) => (
                            <AttentionRow
                                key={item.serviceId}
                                project={project}
                                environment={environment}
                                item={item}
                                canDeploy={canDeploy}
                                onDone={() => setOpen(false)}
                            />
                        ))}
                    </ul>
                )}
            </PopoverContent>
        </Popover>
    );
}

function AttentionRow({
    project,
    environment,
    item,
    canDeploy,
    onDone,
}: {
    project: string;
    environment: string;
    item: ServiceLiveStatus;
    canDeploy: boolean;
    onDone: () => void;
}) {
    const now = useNow(true, 30_000);
    const { status, problem } = item;
    const remedy = canDeploy ? status.remedy : null;
    const detail = statusDetail(status, now);
    const panel = services.show({
        project,
        environment,
        service: item.serviceId,
        tab: 'deployments',
    }).url;

    return (
        <li className="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-4 py-2.5">
            <ServiceGlyph logo={item.logo} size="sm" />
            <div className="flex min-w-0 flex-1 basis-48 flex-col">
                <Link
                    href={panel}
                    onClick={onDone}
                    className="truncate type-label hover:underline"
                >
                    {item.name}
                </Link>
                <Status
                    state={status.state}
                    label={status.label}
                    detail={detail ?? undefined}
                    className="type-small text-muted-foreground"
                />
            </div>
            <div className="ml-auto flex shrink-0 items-center gap-1">
                {problem && (
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={problem.href} onClick={onDone}>
                            {problem.link}
                        </Link>
                    </Button>
                )}
                {remedy && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => {
                            runRemedy(remedy, {
                                project,
                                environment,
                                service: item.serviceId,
                                name: item.name,
                                deployment: status.deploymentId,
                            });
                            onDone();
                        }}
                    >
                        {vocabulary.ServiceRemedy[remedy].label}
                    </Button>
                )}
            </div>
        </li>
    );
}
