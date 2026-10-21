import { Link, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { settings } from '@/routes/backups';

/**
 * Persistent warning for instance owners until a recovery kit is confirmed
 * (docs/14 §4, `instance_settings.recovery_kit_confirmed_at`).
 */
export function RecoveryKitBanner() {
    const { recoveryKit } = usePage().props;

    if (!recoveryKit?.needsAttention) {
        return null;
    }

    const message = !recoveryKit.destinationConfigured
        ? 'The control plane is not backed up. Add a backup destination and save a recovery kit.'
        : !recoveryKit.passphraseSet
          ? 'Set a recovery passphrase so control-plane backups can include the keys.'
          : 'Download the recovery kit and confirm you stored it.';

    return (
        <div
            role="status"
            className="flex flex-wrap items-center gap-2 border-b px-4 py-2 type-small"
        >
            <TriangleAlert
                aria-hidden="true"
                className="size-3.5 shrink-0 text-status-progress"
            />
            <span>{message}</span>
            <Link
                href={settings()}
                className="font-medium underline underline-offset-4"
            >
                Open backup settings
            </Link>
        </div>
    );
}
