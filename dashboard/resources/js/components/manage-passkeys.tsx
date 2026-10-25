import { router } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { destroy } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController';
import PasskeyItem from '@/components/passkey-item';
import PasskeyRegistration from '@/components/passkey-register';
import {
    SettingsBody,
    SettingsCard,
    SettingsRow,
} from '@/features/settings/settings-card';
import type { Passkey } from '@/types/auth';

export type Props = {
    canManagePasskeys?: boolean;
    passkeys?: Passkey[];
};

export default function ManagePasskeys(props: Props) {
    const passkeys = props.passkeys ?? [];

    const handleDelete = (id: number, onError: () => void) => {
        router.delete(destroy.url(id), {
            preserveScroll: true,
            onError,
        });
    };

    const handleRegisterSuccess = () => {
        router.reload();
    };

    if (!(props.canManagePasskeys ?? false)) {
        return null;
    }

    return (
        <SettingsCard
            title="Passkeys"
            setting="passkeys"
            description="Sign in with your fingerprint or face instead of a password."
        >
            {passkeys.length > 0 ? (
                passkeys.map((passkey) => (
                    <PasskeyItem
                        key={passkey.id}
                        passkey={passkey}
                        onDelete={handleDelete}
                    />
                ))
            ) : (
                <SettingsRow
                    media={
                        <span className="flex size-9 items-center justify-center rounded-md border bg-card text-muted-foreground">
                            <KeyRound aria-hidden="true" className="size-4" />
                        </span>
                    }
                    label="No passkeys yet"
                    description="Add one on each device you sign in from."
                />
            )}
            <SettingsBody className="py-4">
                <PasskeyRegistration onSuccess={handleRegisterSuccess} />
            </SettingsBody>
        </SettingsCard>
    );
}
