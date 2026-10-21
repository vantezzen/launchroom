import { Form, Head } from '@inertiajs/react';
import { Check } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    ServerDevice,
    serverFacts,
} from '@/features/onboarding/server-summary';
import { store } from '@/routes/setup';

type Props = {
    token: string;
    passwordRules: string;
    /** The server the installer connected (only with a valid token). */
    server: App.Data.Servers.ServerData | null;
    instanceUrl: string;
};

/**
 * First run (flows §1, direction §9.15): left, the "it worked" moment with
 * the server that's already connected; right, the owner account.
 */
export default function Setup({
    token,
    passwordRules,
    server,
    instanceUrl,
}: Props) {
    const facts = server ? serverFacts(server) : [];
    const online = server?.status === 'online';

    return (
        <div className="grid min-h-svh bg-background lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <Head title="Set up launchroom" />

            <section className="flex flex-col justify-between gap-10 border-b bg-recessed px-6 py-8 sm:px-10 lg:border-r lg:border-b-0 lg:px-14 lg:py-12">
                <AppLogoIcon className="size-8 text-foreground" />

                <div className="flex flex-col items-center gap-8 text-center lg:items-start lg:text-left">
                    <ServerDevice
                        server={server}
                        size={300}
                        className="max-w-full lg:-ml-4"
                    />
                    <div className="flex max-w-[420px] flex-col gap-3">
                        <h1 className="type-display">
                            Your launchroom is installed.
                        </h1>
                        {server ? (
                            <p className="type-body text-muted-foreground">
                                <span className="font-medium text-foreground">
                                    {server.name}
                                </span>
                                {facts.map((fact) => (
                                    <span key={fact}> · {fact}</span>
                                ))}
                            </p>
                        ) : (
                            <p className="type-body text-muted-foreground">
                                Create your account to start deploying.
                            </p>
                        )}
                        {online && (
                            <p className="inline-flex items-center gap-1.5 self-center type-small text-status-ok lg:self-start">
                                <Check
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                                This server is already connected
                            </p>
                        )}
                    </div>
                </div>

                <p className="hidden type-mono-small text-muted-foreground lg:block">
                    {instanceUrl.replace(/^https?:\/\//, '')}
                </p>
            </section>

            <section className="flex items-center justify-center px-4 py-12 sm:px-6">
                <div className="flex w-full max-w-[360px] flex-col gap-8">
                    <div className="flex flex-col gap-2">
                        <h2 className="type-title">Create the owner account</h2>
                        <p className="type-body text-muted-foreground">
                            This account runs the instance. Get started guides
                            you next.
                        </p>
                    </div>

                    <Form
                        {...store.form()}
                        resetOnSuccess={['password', 'password_confirmation']}
                        className="flex flex-col gap-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                {token !== '' && !errors.token ? (
                                    <input
                                        type="hidden"
                                        name="token"
                                        value={token}
                                    />
                                ) : (
                                    <div className="grid gap-2">
                                        <Label htmlFor="token">
                                            Setup token
                                        </Label>
                                        <Input
                                            id="token"
                                            name="token"
                                            defaultValue={token}
                                            required
                                            autoComplete="off"
                                            spellCheck={false}
                                            className="type-mono"
                                        />
                                        <p className="type-small text-muted-foreground">
                                            Printed by the installer, or{' '}
                                            <code className="type-mono-small">
                                                ./launchroom setup-token
                                            </code>
                                            . It works once.
                                        </p>
                                        <InputError message={errors.token} />
                                    </div>
                                )}

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
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        name="email"
                                        required
                                        autoComplete="email"
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password">Password</Label>
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

                                <InputError message={errors.general} />

                                <Button
                                    type="submit"
                                    size="lg"
                                    className="w-full"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Create account
                                </Button>
                            </>
                        )}
                    </Form>
                </div>
            </section>
        </div>
    );
}
