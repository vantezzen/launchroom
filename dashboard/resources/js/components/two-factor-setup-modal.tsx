import { Form } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { Check, Copy } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AlertError from '@/components/alert-error';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { confirm } from '@/routes/two-factor';

type Props = {
    isOpen: boolean;
    onClose: () => void;
    requiresConfirmation: boolean;
    twoFactorEnabled: boolean;
    qrCodeSvg: string | null;
    manualSetupKey: string | null;
    clearSetupData: () => void;
    fetchSetupData: () => Promise<void>;
    errors: string[];
};

/** The QR code on a white tile in both themes, so every camera reads it. */
function QrTile({ svg }: { svg: string | null }) {
    return (
        <div className="flex size-60 items-center justify-center rounded-xl border bg-white p-4 shadow-card">
            {svg ? (
                <div
                    className="size-full [&_svg]:size-full"
                    role="img"
                    aria-label="QR code for your authenticator app"
                    dangerouslySetInnerHTML={{ __html: svg }}
                />
            ) : (
                <Spinner className="text-neutral-500" />
            )}
        </div>
    );
}

function SetupKey({ value }: { value: string | null }) {
    const [copied, copy] = useClipboard();
    const Icon = copied === value ? Check : Copy;

    return (
        <div className="flex w-full flex-col items-center gap-2">
            <span className="type-small text-muted-foreground">
                Can't scan? Enter this key instead.
            </span>
            <div className="flex w-full items-center gap-2 rounded-md border bg-recessed py-1 pr-1 pl-3">
                <code className="min-w-0 flex-1 truncate type-mono">
                    {value ?? '…'}
                </code>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    disabled={!value}
                    onClick={() => value && copy(value)}
                    aria-label="Copy setup key"
                >
                    <Icon aria-hidden="true" />
                </Button>
            </div>
        </div>
    );
}

function CodeForm({ onDone }: { onDone: () => void }) {
    const [code, setCode] = useState('');

    return (
        <Form
            {...confirm.form()}
            onSuccess={onDone}
            resetOnError
            resetOnSuccess
            className="flex w-full flex-col items-center gap-4"
        >
            {({
                processing,
                errors,
            }: {
                processing: boolean;
                errors?: { confirmTwoFactorAuthentication?: { code?: string } };
            }) => (
                <>
                    <label
                        htmlFor="otp"
                        className="type-label text-muted-foreground"
                    >
                        Then enter the 6-digit code it shows
                    </label>
                    <InputOTP
                        id="otp"
                        name="code"
                        maxLength={OTP_MAX_LENGTH}
                        onChange={setCode}
                        disabled={processing}
                        pattern={REGEXP_ONLY_DIGITS}
                    >
                        <InputOTPGroup>
                            {Array.from({ length: OTP_MAX_LENGTH }, (_, i) => (
                                <InputOTPSlot key={i} index={i} />
                            ))}
                        </InputOTPGroup>
                    </InputOTP>
                    <InputError
                        message={errors?.confirmTwoFactorAuthentication?.code}
                    />
                    <Button
                        type="submit"
                        size="lg"
                        className="w-full"
                        disabled={processing || code.length < OTP_MAX_LENGTH}
                    >
                        {processing && <Spinner />}
                        Turn on two-factor
                    </Button>
                </>
            )}
        </Form>
    );
}

/**
 * Two-factor enrolment as one calm, full-height step (design-system §13.9):
 * a large QR code, the setup key for typing, and the code field on the same
 * screen. No icon art, no second dialog.
 */
export default function TwoFactorSetupModal({
    isOpen,
    onClose,
    requiresConfirmation,
    twoFactorEnabled,
    qrCodeSvg,
    manualSetupKey,
    clearSetupData,
    fetchSetupData,
    errors,
}: Props) {
    const fetchRef = useRef(fetchSetupData);

    useEffect(() => {
        fetchRef.current = fetchSetupData;
    }, [fetchSetupData]);

    useEffect(() => {
        if (isOpen && !qrCodeSvg && !twoFactorEnabled) {
            void fetchRef.current();
        }
    }, [isOpen, qrCodeSvg, twoFactorEnabled]);

    const close = () => {
        if (twoFactorEnabled || !requiresConfirmation) {
            clearSetupData();
        }

        onClose();
    };

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && close()}>
            <DialogContent className="top-0 left-0 flex h-svh max-h-svh w-full max-w-none translate-x-0 translate-y-0 items-start justify-center overflow-y-auto rounded-none border-0 bg-background p-0 shadow-none sm:max-w-none">
                <div className="flex min-h-full w-full max-w-[400px] flex-col items-center justify-center gap-8 px-4 py-12 text-center">
                    <div className="flex flex-col gap-2">
                        <DialogTitle className="type-title">
                            Set up two-factor
                        </DialogTitle>
                        <DialogDescription className="type-body text-muted-foreground">
                            Scan this code with your authenticator app.
                        </DialogDescription>
                    </div>

                    {errors.length > 0 ? (
                        <AlertError errors={errors} />
                    ) : (
                        <>
                            <QrTile svg={qrCodeSvg} />
                            <SetupKey value={manualSetupKey} />
                            {requiresConfirmation ? (
                                <CodeForm onDone={close} />
                            ) : (
                                <Button
                                    size="lg"
                                    className="w-full"
                                    onClick={close}
                                >
                                    Done
                                </Button>
                            )}
                        </>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
