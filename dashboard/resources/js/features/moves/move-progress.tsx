import { Form } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import { Status } from '@/components/status';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { itemStates, kindLabels, moveStates } from '@/features/moves/format';
import { formatBytes, plural } from '@/lib/format';
import { deviceStatus } from '@/features/servers/format';
import { TransferStrip } from '@/features/moves/transfer-strip';

type FormDefinition = {
    action: string;
    method: 'get' | 'post' | 'put' | 'patch' | 'delete';
};

/**
 * Progress of a server move (docs/14 §13.2): the overall count and one row
 * per service with its step, what it said last and, when it failed, Retry.
 */
export function MoveProgress({
    move,
    retryForm,
}: {
    move: App.Data.Moves.ServerMoveData;
    retryForm: (item: string) => FormDefinition;
}) {
    const total = move.items.length;
    const [state, label] = moveStates[move.status];
    const current = move.items.find(
        (item) => itemStates[item.status][0] === 'progress',
    );
    const finished = move.status === 'ready' || move.status === 'completed';
    const step = finished
        ? 'Everything moved'
        : current
          ? [
                `${itemStates[current.status][1]} ${current.name}`,
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
        <section
            className="flex flex-col gap-4"
            aria-labelledby="move-progress"
        >
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                <h2 id="move-progress" className="type-heading">
                    {move.source.name} to {move.target.name}
                </h2>
                <Status state={state} label={label} />
            </div>
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
                        total > 0
                            ? `${plural(move.done, 'service')} here`
                            : null,
                    serving: targetServes,
                }}
                step={step}
            />
            {total > 0 && (
                <div className="flex flex-col gap-1.5">
                    <Progress
                        value={(move.done / total) * 100}
                        aria-label="Services moved"
                    />
                    <span className="type-small text-muted-foreground tabular-nums">
                        {move.done} of {total} moved
                        {move.failed > 0 && `, ${move.failed} need attention`}
                    </span>
                </div>
            )}
            {move.error !== null && <p className="type-body">{move.error}</p>}
            {total === 0 ? (
                <p className="type-body text-muted-foreground">
                    Nothing ran on {move.source.name}.
                </p>
            ) : (
                <ul className="flex flex-col divide-y rounded-xl border bg-card shadow-card">
                    {move.items.map((item) => {
                        const [itemState, itemLabel] = itemStates[item.status];

                        return (
                            <li
                                key={item.id}
                                className="flex flex-col gap-1 px-5 py-4"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="min-w-0 type-body break-words">
                                        {item.name}
                                    </span>
                                    <Status
                                        state={itemState}
                                        label={itemLabel}
                                    />
                                </div>
                                <span className="flex flex-wrap gap-x-3 gap-y-1 type-small text-muted-foreground">
                                    <span>{kindLabels[item.kind]}</span>
                                    {item.copiedBytes !== null && (
                                        <span>
                                            {formatBytes(item.copiedBytes)}{' '}
                                            copied
                                        </span>
                                    )}
                                    {item.copiedBytes === null &&
                                        item.expectedBytes !== null && (
                                            <span>
                                                about{' '}
                                                {formatBytes(
                                                    item.expectedBytes,
                                                )}
                                            </span>
                                        )}
                                    {item.attempts > 1 && (
                                        <span>attempt {item.attempts}</span>
                                    )}
                                </span>
                                {item.message !== null && (
                                    <span className="type-small break-words text-muted-foreground">
                                        {item.message}
                                    </span>
                                )}
                                {item.canRetry && (
                                    <Form
                                        {...retryForm(item.id)}
                                        options={{ preserveScroll: true }}
                                        className="pt-1"
                                    >
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
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
