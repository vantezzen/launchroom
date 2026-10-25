import { Form } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { StatusPill } from '@/components/status';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Button } from '@/components/ui/button';
import {
    SettingsBody,
    SettingsCard,
    SettingsRow,
} from '@/features/settings/settings-card';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

export default function ManageTwoFactor(props: Props) {
    const requiresConfirmation = props.requiresConfirmation ?? false;
    const twoFactorEnabled = props.twoFactorEnabled ?? false;

    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        clearTwoFactorAuthData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState<boolean>(false);
    const prevTwoFactorEnabled = useRef(twoFactorEnabled);

    useEffect(() => {
        if (prevTwoFactorEnabled.current && !twoFactorEnabled) {
            clearTwoFactorAuthData();
        }

        prevTwoFactorEnabled.current = twoFactorEnabled;
    }, [twoFactorEnabled, clearTwoFactorAuthData]);

    if (!(props.canManageTwoFactor ?? false)) {
        return null;
    }

    return (
        <SettingsCard
            title="Two-factor authentication"
            setting="two-factor"
            actions={
                twoFactorEnabled ? (
                    <StatusPill state="active" label="On" />
                ) : (
                    <StatusPill state="idle" label="Off" />
                )
            }
        >
            <SettingsRow
                media={
                    <span className="flex size-9 items-center justify-center rounded-md border bg-card text-muted-foreground">
                        <ShieldCheck aria-hidden="true" className="size-4" />
                    </span>
                }
                label="Authenticator app"
                description={
                    twoFactorEnabled
                        ? 'Sign-in asks for a code from your app.'
                        : 'Recommended. Sign-in also asks for a code from an authenticator app, so a leaked password alone isn’t enough.'
                }
            >
                {twoFactorEnabled ? (
                    <Form {...disable.form()}>
                        {({ processing }) => (
                            <Button
                                variant="destructive"
                                size="sm"
                                type="submit"
                                disabled={processing}
                            >
                                Turn off
                            </Button>
                        )}
                    </Form>
                ) : hasSetupData ? (
                    <Button size="sm" onClick={() => setShowSetupModal(true)}>
                        Continue setup
                    </Button>
                ) : (
                    <Form
                        {...enable.form()}
                        onSuccess={() => setShowSetupModal(true)}
                    >
                        {({ processing }) => (
                            <Button
                                size="sm"
                                type="submit"
                                disabled={processing}
                            >
                                Turn on
                            </Button>
                        )}
                    </Form>
                )}
            </SettingsRow>
            {twoFactorEnabled && (
                <SettingsBody className="py-4">
                    <TwoFactorRecoveryCodes
                        recoveryCodesList={recoveryCodesList}
                        fetchRecoveryCodes={fetchRecoveryCodes}
                        errors={errors}
                    />
                </SettingsBody>
            )}

            <TwoFactorSetupModal
                isOpen={showSetupModal}
                onClose={() => setShowSetupModal(false)}
                requiresConfirmation={requiresConfirmation}
                twoFactorEnabled={twoFactorEnabled}
                qrCodeSvg={qrCodeSvg}
                manualSetupKey={manualSetupKey}
                clearSetupData={clearSetupData}
                fetchSetupData={fetchSetupData}
                errors={errors}
            />
        </SettingsCard>
    );
}
