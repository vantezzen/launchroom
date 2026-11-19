import { router } from '@inertiajs/react';
import { ChevronRight, HardDrive } from 'lucide-react';
import { useState } from 'react';
import { StatusIcon } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Spinner } from '@/components/ui/spinner';
import { listNames, plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import { ActionReason } from './action-reason';
import type { NotBackedUpItem } from './page-types';

function reasonLine(item: NotBackedUpItem) {
    switch (item.reason) {
        case 'volume_off':
            return (
                <>
                    Turned off
                    {item.turnedOffBy !== null && ` by ${item.turnedOffBy}`}
                    {item.turnedOffAt !== null && (
                        <>
                            {' '}
                            <TimeAgo value={item.turnedOffAt} />
                        </>
                    )}
                    .
                </>
            );
        case 'schedule_off':
            return (
                <>
                    Automatic backups paused
                    {item.turnedOffAt !== null && (
                        <>
                            {' '}
                            <TimeAgo value={item.turnedOffAt} />
                        </>
                    )}
                    .
                </>
            );
        case 'preview':
            return 'Previews aren’t backed up.';
        case 'no_schedule':
            return 'Not on a backup schedule.';
    }
}

function TurnOn({ item }: { item: NotBackedUpItem }) {
    const [busy, setBusy] = useState(false);
    const action = item.turnOn;

    if (action === null) {
        return null;
    }

    if (action.url === null) {
        return action.disabled !== null ? (
            <ActionReason reason={action.disabled} />
        ) : null;
    }

    const url = action.url;

    return (
        <Button
            size="sm"
            variant="outline"
            disabled={busy}
            onClick={() =>
                router.put(
                    url,
                    { enabled: true },
                    {
                        preserveScroll: true,
                        onStart: () => setBusy(true),
                        onFinish: () => setBusy(false),
                    },
                )
            }
        >
            {busy && <Spinner />}
            Turn on
        </Button>
    );
}

/**
 * What isn't backed up, and why (owner decision 3): turned-off volumes
 * with who did it and when, paused schedules, previews. Collapsed, so the
 * page leads with what is protected, but never hidden.
 */
export function NotBackedUp({ items }: { items: NotBackedUpItem[] }) {
    const [open, setOpen] = useState(false);

    if (items.length === 0) {
        return null;
    }

    const previews = items.filter((item) => item.reason === 'preview');
    const others = items.filter((item) => item.reason !== 'preview');
    const teaser = [
        ...others
            .slice(0, 2)
            .map((item) =>
                item.serviceName !== null && item.kind === 'volume'
                    ? `${item.name} on ${item.serviceName}`
                    : item.name,
            ),
        ...(previews.length > 0 ? [plural(previews.length, 'preview')] : []),
    ];

    return (
        <Collapsible open={open} onOpenChange={setOpen}>
            <CollapsibleTrigger className="group flex w-full items-center gap-2 rounded-md py-1 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                <ChevronRight
                    aria-hidden="true"
                    className={cn(
                        'size-4 shrink-0 text-muted-foreground transition-transform duration-fast',
                        open && 'rotate-90',
                    )}
                />
                <span className="shrink-0 type-heading">Not backed up</span>
                <span className="type-body text-muted-foreground tabular-nums">
                    {items.length}
                </span>
                {!open && (
                    <span className="hidden min-w-0 truncate type-small text-muted-foreground sm:inline">
                        {listNames(teaser)}
                    </span>
                )}
            </CollapsibleTrigger>
            <CollapsibleContent>
                <ul className="mt-3 divide-y overflow-hidden rounded-xl border bg-card shadow-card">
                    {items.map((item) => (
                        <li
                            key={item.id}
                            className="flex flex-wrap items-center gap-x-3.5 gap-y-2 px-4 py-3 sm:px-5"
                        >
                            <span className="flex size-8 shrink-0 items-center justify-center rounded-[9px] border bg-card text-muted-foreground">
                                {item.kind === 'volume' ? (
                                    <HardDrive
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                ) : (
                                    <StatusIcon state="idle" />
                                )}
                            </span>
                            <div className="flex min-w-0 flex-1 basis-56 flex-col gap-0.5">
                                <span
                                    className={cn(
                                        'truncate type-body font-medium',
                                        item.kind === 'volume' &&
                                            'font-mono text-[13px]',
                                    )}
                                >
                                    {item.name}
                                    {item.kind === 'volume' &&
                                        item.serviceName !== null && (
                                            <span className="font-sans font-normal text-muted-foreground">
                                                {' '}
                                                on {item.serviceName}
                                            </span>
                                        )}
                                </span>
                                <span className="type-small text-muted-foreground">
                                    {[item.projectName, item.environmentName]
                                        .filter(Boolean)
                                        .join(', ')}
                                    {item.projectName !== null && '. '}
                                    {reasonLine(item)}
                                </span>
                            </div>
                            <TurnOn item={item} />
                        </li>
                    ))}
                </ul>
            </CollapsibleContent>
        </Collapsible>
    );
}
