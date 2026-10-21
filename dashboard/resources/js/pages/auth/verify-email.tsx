import { Form, Head, Link } from '@inertiajs/react';
import { MailCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { AuthFrame, AuthNotice } from '@/features/auth/auth-frame';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <AuthFrame
            title="Check your inbox"
            description="Open the link we emailed you to verify your address."
            media={
                <span className="flex size-12 items-center justify-center rounded-xl border bg-card">
                    <MailCheck
                        className="size-6 text-muted-foreground"
                        aria-hidden="true"
                    />
                </span>
            }
            footer={
                <Link
                    href={logout()}
                    as="button"
                    className="underline-offset-4 hover:text-foreground hover:underline"
                >
                    Sign out
                </Link>
            }
        >
            <Head title="Verify your email" />

            {status === 'verification-link-sent' && (
                <AuthNotice>We sent a new link.</AuthNotice>
            )}

            <Form {...send.form()}>
                {({ processing }) => (
                    <Button
                        variant="outline"
                        size="lg"
                        className="w-full"
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        Send the link again
                    </Button>
                )}
            </Form>
        </AuthFrame>
    );
}
