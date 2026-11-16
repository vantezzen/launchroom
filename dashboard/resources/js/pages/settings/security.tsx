import { Head } from '@inertiajs/react';
import { RectangleEllipsis } from 'lucide-react';
import { SettingsCard, SettingsRow } from '@/components/settings/settings-card';
import { ChangePasswordDialog } from '@/features/account/change-password-dialog';
import type { Props as ManagePasskeysProps } from '@/features/account/manage-passkeys';
import ManagePasskeys from '@/features/account/manage-passkeys';
import type { Props as ManageTwoFactorProps } from '@/features/account/manage-two-factor';
import ManageTwoFactor from '@/features/account/manage-two-factor';
import SettingsLayout from '@/features/settings/settings-layout';

// oxfmt-ignore
type Props = {
    passwordRules: string;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

/**
 * Settings → Sign-in: what protects the account first (two-factor,
 * passkeys), the password as one row whose form opens on demand.
 */
export default function Security(props: Props) {
    return (
        <>
            <Head title="Sign-in" />

            <ManageTwoFactor
                canManageTwoFactor={props.canManageTwoFactor}
                requiresConfirmation={props.requiresConfirmation}
                twoFactorEnabled={props.twoFactorEnabled}
            />

            <ManagePasskeys
                canManagePasskeys={props.canManagePasskeys}
                passkeys={props.passkeys}
            />

            <SettingsCard setting="password">
                <SettingsRow
                    media={
                        <span className="flex size-9 items-center justify-center rounded-md border bg-card text-muted-foreground">
                            <RectangleEllipsis
                                aria-hidden="true"
                                className="size-4"
                            />
                        </span>
                    }
                    label="Password"
                    description="Use a long, random password, ideally from a password manager."
                    inline
                >
                    <ChangePasswordDialog passwordRules={props.passwordRules} />
                </SettingsRow>
            </SettingsCard>
        </>
    );
}

Security.layout = SettingsLayout;
