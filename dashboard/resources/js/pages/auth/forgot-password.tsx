import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { AuthFrame, AuthNotice } from '@/features/auth/auth-frame';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    return (
        <AuthFrame
            title="Reset your password"
            description="We’ll email you a link to choose a new one."
            footer={
                <Link
                    href={login()}
                    className="underline-offset-4 hover:text-foreground hover:underline"
                >
                    Back to sign in
                </Link>
            }
        >
            <Head title="Reset your password" />

            {status && <AuthNotice>{status}</AuthNotice>}

            <Form {...email.form()} className="flex flex-col gap-5">
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="email"
                                autoFocus
                                placeholder="you@example.com"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <Button
                            size="lg"
                            className="w-full"
                            disabled={processing}
                            data-test="email-password-reset-link-button"
                        >
                            {processing && <Spinner />}
                            Email me a reset link
                        </Button>
                    </>
                )}
            </Form>
        </AuthFrame>
    );
}
