import { Form, Head } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { AuthFrame } from '@/features/auth/auth-frame';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { store } from '@/routes/two-factor/login';

/**
 * Second factor (direction §9.15): one large code input; a recovery code is
 * the alternative one click away.
 */
export default function TwoFactorChallenge() {
    const [recovery, setRecovery] = useState(false);
    const [code, setCode] = useState('');

    return (
        <AuthFrame
            title={recovery ? 'Use a recovery code' : 'Enter your code'}
            description={
                recovery
                    ? 'One of the codes you saved when you turned on two-factor.'
                    : 'The 6-digit code from your authenticator app.'
            }
            media={
                <span className="flex size-12 items-center justify-center rounded-xl border bg-card">
                    <ShieldCheck
                        className="size-6 text-muted-foreground"
                        aria-hidden="true"
                    />
                </span>
            }
        >
            <Head title="Two-factor authentication" />

            <Form
                {...store.form()}
                className="flex flex-col gap-6"
                resetOnError
                resetOnSuccess={!recovery}
            >
                {({ errors, processing, clearErrors }) => (
                    <>
                        {recovery ? (
                            <div className="grid gap-2">
                                <Input
                                    name="recovery_code"
                                    type="text"
                                    aria-label="Recovery code"
                                    placeholder="xxxxxxxxxx-xxxxxxxxxx"
                                    autoComplete="one-time-code"
                                    spellCheck={false}
                                    className="h-12 text-center type-mono"
                                    autoFocus
                                    required
                                />
                                <InputError message={errors.recovery_code} />
                            </div>
                        ) : (
                            <div className="flex flex-col items-center gap-3">
                                <InputOTP
                                    name="code"
                                    maxLength={OTP_MAX_LENGTH}
                                    value={code}
                                    onChange={(value) => setCode(value)}
                                    disabled={processing}
                                    pattern={REGEXP_ONLY_DIGITS}
                                    aria-label="Authentication code"
                                    autoFocus
                                >
                                    <InputOTPGroup className="gap-2">
                                        {Array.from(
                                            { length: OTP_MAX_LENGTH },
                                            (_, index) => (
                                                <InputOTPSlot
                                                    key={index}
                                                    index={index}
                                                    className="size-12 rounded-md border text-lg first:rounded-md last:rounded-md"
                                                />
                                            ),
                                        )}
                                    </InputOTPGroup>
                                </InputOTP>
                                <InputError message={errors.code} />
                            </div>
                        )}

                        <Button
                            type="submit"
                            size="lg"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Continue
                        </Button>

                        <button
                            type="button"
                            className="mx-auto type-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                            onClick={() => {
                                setRecovery(!recovery);
                                setCode('');
                                clearErrors();
                            }}
                        >
                            {recovery
                                ? 'Use your authenticator app instead'
                                : 'Lost your device? Use a recovery code'}
                        </button>
                    </>
                )}
            </Form>
        </AuthFrame>
    );
}
