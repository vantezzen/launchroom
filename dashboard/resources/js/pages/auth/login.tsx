import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    AuthFrame,
    AuthNotice,
    OrDivider,
    PasskeySignIn,
} from '@/features/auth/auth-frame';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

/**
 * Sign in (direction §9.15): passkey first, then email and password.
 */
export default function Login({ status, canResetPassword }: Props) {
    return (
        <AuthFrame
            title="Sign in to launchroom"
            footer="Accounts are invite-only. Ask a team admin for an invitation."
        >
            <Head title="Sign in" />

            {status && <AuthNotice>{status}</AuthNotice>}

            <div className="flex flex-col gap-6">
                <PasskeySignIn
                    after={<OrDivider>or use your email</OrDivider>}
                />

                <Form
                    {...store.form()}
                    resetOnSuccess={['password']}
                    className="flex flex-col gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    autoComplete="email webauthn"
                                    placeholder="you@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-baseline justify-between gap-3">
                                    <Label htmlFor="password">Password</Label>
                                    {canResetPassword && (
                                        <Link
                                            href={request()}
                                            className="type-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                                        >
                                            Forgot it?
                                        </Link>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete="current-password"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center gap-2.5">
                                <Checkbox id="remember" name="remember" />
                                <Label
                                    htmlFor="remember"
                                    className="font-normal text-muted-foreground"
                                >
                                    Keep me signed in
                                </Label>
                            </div>

                            <Button
                                type="submit"
                                size="lg"
                                className="w-full"
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Sign in
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </AuthFrame>
    );
}
