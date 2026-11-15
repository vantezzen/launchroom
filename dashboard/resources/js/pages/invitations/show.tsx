import { vocabulary } from '@/lib/vocabulary';
import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { AuthFrame } from '@/features/auth/auth-frame';
import { login } from '@/routes';
import { accept } from '@/routes/invitations';
import { initials } from '@/lib/format';
import { noLayout } from '@/lib/pages';

type Props = {
    token: string;
    invitation: App.Data.Teams.InvitationPreviewData;
    signedInAs: string | null;
    passwordRules: string;
};

/**
 * Invitation acceptance (flows §16): the team, who invited you and as what,
 * then one action: join.
 */
export default function ShowInvitation({
    token,
    invitation,
    signedInAs,
    passwordRules,
}: Props) {
    const role = vocabulary.TeamRole[invitation.role].label;
    const needsLogin = signedInAs === null && invitation.hasAccount;
    const wrongAccount =
        signedInAs !== null &&
        signedInAs.toLowerCase() !== invitation.email.toLowerCase();

    return (
        <AuthFrame
            title={`Join ${invitation.teamName}`}
            description={
                <>
                    {invitation.invitedBy ?? 'Someone'} invited{' '}
                    <span className="text-foreground">{invitation.email}</span>.
                </>
            }
            media={
                <span
                    aria-hidden="true"
                    className="flex size-14 items-center justify-center rounded-2xl bg-foreground type-heading text-background"
                >
                    {initials(invitation.teamName.replace(/[’'`]s team$/i, ''))}
                </span>
            }
        >
            <Head title={`Join ${invitation.teamName}`} />

            <div className="flex items-center justify-between gap-4 rounded-xl border bg-card px-4 py-3">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <span className="type-label">{role}</span>
                    <span className="type-small text-muted-foreground">
                        {vocabulary.TeamRole[invitation.role].summary}
                    </span>
                </div>
            </div>

            {needsLogin && (
                <div className="flex flex-col gap-3">
                    <p className="text-center type-body text-muted-foreground">
                        You already have an account. Sign in, then open this
                        link again.
                    </p>
                    <Button asChild size="lg" className="w-full">
                        <Link href={login()}>Sign in</Link>
                    </Button>
                </div>
            )}

            {wrongAccount && (
                <p className="text-center type-body text-muted-foreground">
                    You’re signed in as{' '}
                    <span className="text-foreground">{signedInAs}</span>. This
                    invitation is for {invitation.email}.
                </p>
            )}

            {!needsLogin && !wrongAccount && (
                <Form
                    {...accept.form(token)}
                    resetOnError={['password', 'password_confirmation']}
                    className="flex flex-col gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            {signedInAs === null && (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">Your name</Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            autoFocus
                                            autoComplete="name"
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="password">
                                            Password
                                        </Label>
                                        <PasswordInput
                                            id="password"
                                            name="password"
                                            required
                                            autoComplete="new-password"
                                            passwordrules={passwordRules}
                                        />
                                        <InputError message={errors.password} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="password_confirmation">
                                            Repeat password
                                        </Label>
                                        <PasswordInput
                                            id="password_confirmation"
                                            name="password_confirmation"
                                            required
                                            autoComplete="new-password"
                                        />
                                    </div>
                                </>
                            )}

                            <InputError message={errors.token} />

                            <Button
                                type="submit"
                                size="lg"
                                className="w-full"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                Join {invitation.teamName}
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </AuthFrame>
    );
}

ShowInvitation.layout = noLayout;
