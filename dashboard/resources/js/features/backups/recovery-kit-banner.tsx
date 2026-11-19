import { Link, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { index as backupsPage } from '@/routes/backups';

/**
 * Persistent warning for instance owners until a recovery kit is confirmed
 * (docs/14 §4, `instance_settings.recovery_kit_confirmed_at`).
 */
export function RecoveryKitBanner() {
    const { recoveryKit } = usePage().props;

    if (!recoveryKit?.needsAttention) {
        return null;
    }

    const [message, fix, hash] = !recoveryKit.destinationConfigured
        ? [
              'launchroom itself isn’t backed up off this server yet.',
              'Protect launchroom itself',
              '#setup-launchroom',
          ]
        : !recoveryKit.passphraseSet
          ? [
                'launchroom’s backups need a recovery passphrase to include its keys.',
                'Set passphrase',
                '#recovery-kit',
            ]
          : [
                'Save the recovery kit so launchroom can come back on a new server.',
                'Save the kit',
                '#recovery-kit',
            ];

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
                href={`${backupsPage().url}${hash}`}
                className="font-medium underline underline-offset-4"
            >
                {fix}
            </Link>
        </div>
    );
}
