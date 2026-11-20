import { Head } from '@inertiajs/react';
import { MovedScreen } from '@/features/moves/moved-screen';
import type { MovedProps } from '@/features/moves/moved-screen';
import { noLayout } from '@/lib/pages';

/**
 * Shown on the old dashboard after launchroom moved to a new server
 * (docs/14 §13.4): every page points at the new address, and instance
 * owners find the DNS records to change in Settings → Move launchroom.
 */
export default function Moved(props: MovedProps) {
    return (
        <>
            <Head title="launchroom moved" />
            <MovedScreen {...props} />
        </>
    );
}

Moved.layout = noLayout;
