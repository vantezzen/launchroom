import { Head } from '@inertiajs/react';
import { AuthFrame } from '@/features/auth/auth-frame';

/**
 * Shown on the old dashboard after launchroom moved to a new server
 * (docs/14 §13.4): every page points at the new address.
 */
export default function Moved({ movedTo }: { movedTo: string }) {
    return (
        <>
            <Head title="launchroom moved" />
            <AuthFrame
                title="launchroom moved"
                description="This dashboard handed over to a new server. Sign in there."
                footer="If the address doesn’t open yet, point its DNS record at the new server."
            >
                <a
                    href={movedTo}
                    className="block rounded-xl border bg-card px-4 py-3 text-center type-mono break-all underline-offset-4 shadow-card hover:underline"
                >
                    {movedTo}
                </a>
            </AuthFrame>
        </>
    );
}
