import { Link, usePage } from '@inertiajs/react';
import { LoaderCircle, MoveRight } from 'lucide-react';
import { edit } from '@/routes/move';

/**
 * Moving launchroom to a new server (docs/14 §13.4): everyone sees that
 * changes are paused during the hand-over; instance owners on the new
 * dashboard see the link to the checklist until they finish it.
 */
export function MoveBanner() {
    const { instanceMove } = usePage().props;

    if (instanceMove === null || instanceMove === undefined) {
        return null;
    }

    return (
        <div
            role="status"
            className="flex flex-wrap items-center gap-2 border-b px-4 py-2 type-small"
        >
            {instanceMove.frozen ? (
                <>
                    <LoaderCircle
                        aria-hidden="true"
                        className="size-3.5 shrink-0 text-status-progress motion-safe:animate-spin"
                    />
                    <span>
                        launchroom is moving to a new server. Changes are paused
                        for a few minutes.
                    </span>
                </>
            ) : (
                <>
                    <MoveRight
                        aria-hidden="true"
                        className="size-3.5 shrink-0 text-muted-foreground"
                    />
                    <span>launchroom moved to this server.</span>
                    <Link
                        href={edit()}
                        className="font-medium underline underline-offset-4"
                    >
                        Open the checklist
                    </Link>
                </>
            )}
        </div>
    );
}
