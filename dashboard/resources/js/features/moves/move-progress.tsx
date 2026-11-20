import { Form, Link } from '@inertiajs/react';
import { ChevronDown, RotateCcw, Square } from 'lucide-react';
import type { ReactNode } from 'react';
import { Fragment, useState } from 'react';
import { Status } from '@/components/status';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { ConfirmForm } from '@/features/moves/confirm-form';
import type { FormDefinition } from '@/features/moves/confirm-form';
import { itemState, kindLabels, moveStates } from '@/features/moves/format';
import { MessageDetail } from '@/features/moves/message-detail';
import { TransferStrip } from '@/features/moves/transfer-strip';
import {
    groupByScope,
    groupItems,
    stopConsequence,
} from '@/features/moves/shape';
import type { MoveItem } from '@/features/moves/types';
import { deviceStatus } from '@/features/servers/format';
import { formatBytes, plural } from '@/lib/format';

type Item = MoveItem;

/** Moved rows shown before "Show all". */
const DONE_SHOWN = 3;

/**
 * Progress of a server move (docs/14 §13.2): both servers, the count, then
 * what needs you (failed services with Retry and Stop), what moves now
 * with its copy progress, what waits, and what already moved (folded).
 */
export function MoveProgress({
    move,
    retryForm,
    stopForm,
    strip = true,
}: {
    move: App.Data.Moves.ServerMoveData;
    retryForm: (item: string) => FormDefinition;
    /** Leave a failed service stopped; omitted where the backend can't. */
    stopForm?: (item: string) => FormDefinition;
    /** The page draws the two servers itself (Move launchroom). */
    strip?: boolean;
}) {
    const items = move.items as Item[];
    const total = items.length;
    const [state, label] = moveStates[move.status];
    const { failed, active, waiting, other, done } = groupItems(items);

    return (
        <section
            className="flex flex-col gap-5"
            aria-labelledby={`move-progress-${move.id}`}
        >
            {strip && (
                <>
                    <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                        <h2
                            id={`move-progress-${move.id}`}
                            className="type-heading"
                        >
                            {move.source.name} to {move.target.name}
                        </h2>
                        <Status state={state} label={label} />
                    </div>
                    <MoveStrip move={move} />
                </>
            )}
            {!strip && (
                <h3 id={`move-progress-${move.id}`} className="sr-only">
                    Services
                </h3>
            )}
            {total > 0 && (
                <div className="flex flex-col gap-1.5">
                    <Progress
                        value={(move.done / total) * 100}
                        aria-label="Services moved"
                    />
                    {strip && (
                        <p className="type-small text-muted-foreground tabular-nums">
                            {move.done} of {total} moved
                            {failed.length > 0 && `, ${failed.length} failed`}
                        </p>
                    )}
                </div>
            )}
            {move.error !== null && (
                <MessageDetail
                    message={move.error}
                    fallback="The move stopped with an error."
                    className="type-body"
                />
            )}
            {total === 0 ? (
                <p className="type-body text-muted-foreground">
                    Nothing ran on {move.source.name}.
                </p>
            ) : (
                <div className="flex flex-col gap-4">
                    {failed.length > 0 && (
                        <Group title="Needs you" items={failed}>
                            {(item) => (
                                <FailedRow
                                    key={item.id}
                                    item={item}
                                    retry={retryForm(item.id)}
                                    stop={
                                        stopForm && item.canStop
                                            ? stopForm(item.id)
                                            : null
                                    }
                                />
                            )}
                        </Group>
                    )}
                    {active.length > 0 && (
                        <Group title="Moving now" items={active}>
                            {(item) => <ActiveRow key={item.id} item={item} />}
                        </Group>
                    )}
                    {waiting.length > 0 && (
                        <Group title="Up next" items={waiting}>
                            {(item) => <CompactRow key={item.id} item={item} />}
                        </Group>
                    )}
                    {other.length > 0 && (
                        <Group title="Not moving" items={other}>
                            {(item) => <CompactRow key={item.id} item={item} />}
                        </Group>
                    )}
                    {done.length > 0 && <DoneGroup items={done} />}
                </div>
            )}
        </section>
    );
}

/** Both servers with the current step on the arrow (docs/08 §20). */
export function MoveStrip({ move }: { move: App.Data.Moves.ServerMoveData }) {
    const total = move.items.length;
    const current = move.items.find(
        (item) => itemState(item)[0] === 'progress',
    );
    const finished = move.status === 'ready' || move.status === 'completed';
    const step = finished
        ? 'Everything moved'
        : current
          ? [
                `${itemState(current)[1]} ${current.name}`,
                current.copiedBytes !== null && current.expectedBytes !== null
                    ? `${formatBytes(current.copiedBytes)} of ${formatBytes(current.expectedBytes)}`
                    : null,
            ]
                .filter(Boolean)
                .join(', ')
          : null;
    // Public traffic follows the edge role, which moves when the source retires.
    const sourceServes =
        move.source.roles.includes('edge') && move.status !== 'completed';
    const targetServes =
        move.target.roles.includes('edge') ||
        (move.status === 'completed' && move.source.roles.includes('edge'));

    return (
        <TransferStrip
            source={{
                name: move.source.name,
                device: move.source.device,
                status: deviceStatus(move.source.status),
                detail:
                    total > 0
                        ? `${plural(total - move.done, 'service')} left`
                        : null,
                serving: sourceServes,
            }}
            target={{
                name: move.target.name,
                device: move.target.device,
                status: deviceStatus(move.target.status),
                detail:
                    total > 0 ? `${plural(move.done, 'service')} here` : null,
                serving: targetServes,
            }}
            step={step}
        />
    );
}

function Group({
    title,
    items,
    children,
    footer,
}: {
    title: ReactNode;
    items: Item[];
    children: (item: Item) => ReactNode;
    /** The last row ("Show 4 more"). */
    footer?: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-2">
            <h4 className="type-label text-muted-foreground">{title}</h4>
            <ul className="flex flex-col divide-y overflow-hidden rounded-xl border bg-card shadow-card">
                <ScopedRows items={items}>{children}</ScopedRows>
                {footer}
            </ul>
        </div>
    );
}

/**
 * Services of one project and environment under a quiet label row, so
 * "web" and "api" of shop / production read as one app.
 */
function ScopedRows({
    items,
    children,
}: {
    items: Item[];
    children: (item: Item) => ReactNode;
}) {
    return groupByScope(items).map(({ scope, items: scoped }) => (
        <Fragment key={scope}>
            <li
                role="presentation"
                className="bg-recessed px-4 py-1.5 type-small text-muted-foreground sm:px-5"
            >
                {scope}
            </li>
            {scoped.map(children)}
        </Fragment>
    ));
}

function Facts({ item }: { item: Item }) {
    return (
        <span className="flex flex-wrap gap-x-3 gap-y-1 type-small text-muted-foreground">
            <span>{kindLabels[item.kind]}</span>
            {item.copiedBytes !== null && (
                <span>{formatBytes(item.copiedBytes)} copied</span>
            )}
            {item.copiedBytes === null && item.expectedBytes !== null && (
                <span>about {formatBytes(item.expectedBytes)}</span>
            )}
            {item.attempts > 1 && <span>attempt {item.attempts}</span>}
        </span>
    );
}

function NameLine({ item }: { item: Item }) {
    const [state, label] = itemState(item);

    return (
        <div className="flex flex-wrap items-center justify-between gap-2">
            <span className="min-w-0 type-body font-medium break-words">
                {item.service}
            </span>
            <Status state={state} label={label} />
        </div>
    );
}

function FailedRow({
    item,
    retry,
    stop,
}: {
    item: Item;
    retry: FormDefinition;
    stop: FormDefinition | null;
}) {
    return (
        <li className="flex flex-col gap-2.5 px-4 py-4 sm:px-5">
            <NameLine item={item} />
            <Facts item={item} />
            {item.message !== null && (
                <MessageDetail
                    message={item.message}
                    fallback="It couldn’t move."
                    className="type-small"
                />
            )}
            {(item.canRetry || stop !== null) && (
                <div className="flex flex-wrap items-center gap-2 pt-0.5">
                    {item.canRetry && (
                        <Form {...retry} options={{ preserveScroll: true }}>
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                    disabled={processing}
                                >
                                    {processing ? (
                                        <Spinner />
                                    ) : (
                                        <RotateCcw aria-hidden="true" />
                                    )}
                                    Retry
                                </Button>
                            )}
                        </Form>
                    )}
                    {stop !== null && (
                        <ConfirmForm
                            title={`Stop ${item.name} instead?`}
                            consequence={stopConsequence(item)}
                            confirmLabel="Stop"
                            align="start"
                            form={stop}
                        >
                            <Button type="button" variant="ghost" size="sm">
                                <Square aria-hidden="true" />
                                Stop…
                            </Button>
                        </ConfirmForm>
                    )}
                </div>
            )}
        </li>
    );
}

function ActiveRow({ item }: { item: Item }) {
    const share =
        item.copiedBytes !== null &&
        item.expectedBytes !== null &&
        item.expectedBytes > 0
            ? Math.min(100, (item.copiedBytes / item.expectedBytes) * 100)
            : null;

    return (
        <li className="flex flex-col gap-2 px-4 py-4 sm:px-5">
            <NameLine item={item} />
            <Facts item={item} />
            {share !== null && (
                <Progress
                    value={share}
                    tone="progress"
                    aria-label={`${item.name} copied`}
                />
            )}
            {item.message !== null && (
                <span className="type-small break-words text-muted-foreground">
                    {item.message}
                </span>
            )}
        </li>
    );
}

function CompactRow({ item }: { item: Item }) {
    const [state, label] = itemState(item);

    return (
        <li className="flex flex-col gap-0.5 px-4 py-3 sm:px-5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="min-w-0 type-body break-words">
                    {item.service}
                </span>
                <Status state={state} label={label} />
            </div>
            {item.status !== 'pending' && item.message !== null && (
                <MessageDetail
                    message={item.message}
                    fallback="It couldn’t move."
                    className="type-small text-muted-foreground"
                />
            )}
            {item.status === 'stopped' && item.href && (
                <Link
                    href={item.href}
                    className="w-fit type-small underline underline-offset-4"
                >
                    Open {item.service}
                </Link>
            )}
        </li>
    );
}

function DoneGroup({ items }: { items: Item[] }) {
    const [all, setAll] = useState(items.length <= DONE_SHOWN + 1);
    // Fold after whole projects where possible: sort by project first.
    const sorted = groupByScope(items).flatMap((group) => group.items);
    const shown = all ? sorted : sorted.slice(0, DONE_SHOWN);

    return (
        <Group
            title={`Moved (${items.length})`}
            items={shown}
            footer={
                !all && (
                    <li>
                        <button
                            type="button"
                            onClick={() => setAll(true)}
                            className="flex w-full items-center gap-1.5 px-4 py-3 text-left type-small text-muted-foreground transition-colors duration-fast hover:bg-hover hover:text-foreground focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none focus-visible:ring-inset sm:px-5"
                        >
                            <ChevronDown
                                aria-hidden="true"
                                className="size-4"
                            />
                            Show {items.length - DONE_SHOWN} more
                        </button>
                    </li>
                )
            }
        >
            {(item) => <CompactRow key={item.id} item={item} />}
        </Group>
    );
}
