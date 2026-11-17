import { Form, Head, Link } from '@inertiajs/react';
import { CopyValue } from '@/components/copy-value';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { AuthFrame, AuthNotice } from '@/features/auth/auth-frame';
import { login } from '@/routes';
import { email } from '@/routes/password';
import { noLayout } from '@/lib/pages';

const COMMAND = './launchroom reset-password you@example.com';

/** Without mail, the way back in is a one-time link printed on the server (OD20). */
function OnTheServer() {
    return (
        <div className="flex flex-col gap-3">
            <p className="type-body text-muted-foreground">
                On the server launchroom runs on, in the folder it is installed
                in, run this with your email. It prints a one-time link to
                choose a new password.
            </p>
            <CopyValue value={COMMAND} label="reset command" />
        </div>
    );
}

/**
 * "Forgot password?". `mailConfigured` false: this launchroom can't send
 * email, so the page shows the server command instead of a form that
 * would send nothing. Unknown (older props): the form, with the command
 * as the fallback below it.
 */
export default function ForgotPassword({
    status,
    mailConfigured,
}: {
    status?: string;
    mailConfigured?: boolean;
}) {
    const footer = (
        <Link
            href={login()}
            className="underline-offset-4 hover:text-foreground hover:underline"
        >
            Back to sign in
        </Link>
    );

    if (mailConfigured === false) {
        return (
            <AuthFrame
                title="Reset your password"
                description="Email isn’t set up for this launchroom, so it can’t send you a link."
                footer={footer}
            >
                <Head title="Reset your password" />
                <OnTheServer />
            </AuthFrame>
        );
    }

    return (
        <AuthFrame
            title="Reset your password"
            description="We’ll email you a link to choose a new one."
            footer={footer}
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

            {mailConfigured === undefined && (
                <details className="type-small text-muted-foreground">
                    <summary className="cursor-pointer">
                        No email arriving?
                    </summary>
                    <div className="pt-3">
                        <OnTheServer />
                    </div>
                </details>
            )}
        </AuthFrame>
    );
}

ForgotPassword.layout = noLayout;
