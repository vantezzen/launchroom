import { Form, Head } from '@inertiajs/react';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    AuthFrame,
    OrDivider,
    PasskeySignIn,
} from '@/features/auth/auth-frame';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    return (
        <AuthFrame
            title="Confirm it’s you"
            description="This change needs your password again."
        >
            <Head title="Confirm it’s you" />

            <div className="flex flex-col gap-6">
                <PasskeySignIn
                    routes={{
                        options: confirmOptions(),
                        submit: confirmStore(),
                    }}
                    label="Confirm with a passkey"
                    after={<OrDivider>or use your password</OrDivider>}
                />

                <Form
                    {...store.form()}
                    resetOnSuccess={['password']}
                    className="flex flex-col gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    autoComplete="current-password"
                                    autoFocus
                                />
                                <InputError message={errors.password} />
                            </div>

                            <Button
                                size="lg"
                                className="w-full"
                                disabled={processing}
                                data-test="confirm-password-button"
                            >
                                {processing && <Spinner />}
                                Confirm
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </AuthFrame>
    );
}
