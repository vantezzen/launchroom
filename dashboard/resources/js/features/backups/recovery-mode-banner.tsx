import { Link, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { show } from '@/routes/recovery';

/**
 * Recovery mode after `install.sh --restore` (docs/14 §5.2): instance
 * owners see this until they finish the recovery checklist.
 */
export function RecoveryModeBanner() {
    const { recoveryMode } = usePage().props;

    if (recoveryMode !== true) {
        return null;
    }

    return (
        <div
            role="status"
            className="flex flex-wrap items-center gap-2 border-b px-4 py-2 type-small"
        >
            <TriangleAlert
                aria-hidden="true"
                className="size-3.5 shrink-0 text-status-progress"
            />
            <span>
                Recovery mode: the control plane was restored from a backup.
            </span>
            <Link
                href={show()}
                className="font-medium underline underline-offset-4"
            >
                Open the recovery checklist
            </Link>
        </div>
    );
}
