import { Form } from '@inertiajs/react';
import type { StatusState } from '@/components/status';
import { Status } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type Request = App.Data.Backups.DeletionRequestData;

const labels: Record<App.Enums.Backups.DeletionRequestStatus, string> = {
    backing_up: 'Taking a final backup',
    blocked: 'Final backup failed',
    ready: 'Final backup done, deleting',
    overridden: 'Deleting without a final backup',
    completed: 'Deleted',
    cancelled: 'Cancelled',
};

function state(status: App.Enums.Backups.DeletionRequestStatus): StatusState {
    if (status === 'blocked') {
        return 'failed';
    }

    return status === 'completed' ? 'active' : 'progress';
}

const runStates: Record<string, StatusState> = {
    pending: 'progress',
    running: 'progress',
    succeeded: 'active',
    failed: 'failed',
};

/**
 * A deletion waiting for its final backup (docs/14 §9.13): which databases
 * and volumes are backed up, why it is blocked, and the two ways on:
 * "Retry final backup" or "Delete without final backup" (owners/admins,
 * authorized, audited).
 */
export function PendingDeletion({
    request,
    onChange,
}: {
    request: Request;
    onChange?: () => void;
}) {
    const what =
        request.subjectType === 'service_instance'
            ? 'The removal'
            : `Deleting ${request.subjectName}`;

    return (
        <div
            role="status"
            className="flex flex-col gap-3 rounded-md border p-4"
            aria-label={labels[request.status]}
        >
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <Status
                    state={state(request.status)}
                    label={labels[request.status]}
                />
                {request.createdAt && (
                    <span className="type-small text-muted-foreground">
                        requested <TimeAgo value={request.createdAt} />
                    </span>
                )}
            </div>

            <p className="max-w-[65ch] type-body text-muted-foreground">
                {request.status === 'blocked'
                    ? `${what} is blocked: nothing was deleted. Retry the final backup, or delete without one.`
                    : request.status === 'backing_up'
                      ? `${what} waits for a final backup of its data and continues once the backup succeeds.`
                      : `${what} continues.`}
            </p>

            {request.error !== null && (
                <p className="type-mono-small break-words text-muted-foreground">
                    {request.error}
                </p>
            )}

            {request.runs.length > 0 && (
                <ul className="flex flex-col gap-1">
                    {request.runs.map((run, index) => (
                        <li
                            key={`${run.service}-${run.volume ?? ''}-${index}`}
                            className="flex flex-wrap items-center gap-x-3"
                        >
                            <Status
                                state={runStates[run.status] ?? 'progress'}
                                label={run.status}
                            />
                            <span className="type-small">
                                {run.service}
                                {run.volume !== null && (
                                    <span className="type-mono-small text-muted-foreground">
                                        {' '}
                                        {run.volume}
                                    </span>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {(request.canRetry || request.canSkip) && (
                <div className="flex flex-wrap gap-2">
                    {request.canRetry && (
                        <Form
                            action={request.urls.retry}
                            method="post"
                            options={{ preserveScroll: true }}
                            onSuccess={() => onChange?.()}
                        >
                            {({ processing }) => (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Retry final backup
                                </Button>
                            )}
                        </Form>
                    )}
                    {request.canSkip && (
                        <Form
                            action={request.urls.skip}
                            method="post"
                            options={{ preserveScroll: true }}
                            onSuccess={() => onChange?.()}
                        >
                            {({ processing }) => (
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Delete without final backup
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
            )}
        </div>
    );
}
