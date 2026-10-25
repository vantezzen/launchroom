import { Form, Head, router, useForm } from '@inertiajs/react';
import { CircleCheck, CircleX, Clock, Laptop } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { MetaDivider } from '@/components/patterns/meta-line';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { AuthFrame } from '@/features/auth/auth-frame';
import { approve, deny, show } from '@/routes/cli-login';

type Props = {
    code: string | null;
    login: App.Data.Api.CliLoginRequestData | null;
    teamOptions: App.Data.Api.ApiTeamOptionData[];
    lifetimes: number[];
    defaultLifetime: number;
};

function lifetimeLabel(days: number): string {
    return days === 365 ? '1 year' : `${days} days`;
}

function Outcome({
    icon,
    title,
    children,
}: {
    icon: ReactNode;
    title: string;
    children: ReactNode;
}) {
    return (
        <>
            <Head title="CLI sign-in" />
            <AuthFrame
                title={title}
                description={children}
                media={
                    <span className="flex size-12 items-center justify-center rounded-xl bg-recessed">
                        {icon}
                    </span>
                }
            >
                <span />
            </AuthFrame>
        </>
    );
}

/**
 * "Sign in to the launchroom CLI?" (docs/16 §4): the person compares the
 * code with their terminal, then approves into one of their teams with the
 * abilities and lifetime they pick. Calm, one decision, no layout chrome.
 */
export default function CliLogin({
    code,
    login,
    teamOptions: teams,
    lifetimes,
    defaultLifetime,
}: Props) {
    if (login === null) {
        return <EnterCode code={code} />;
    }

    if (login.status === 'approved' || login.status === 'consumed') {
        return (
            <Outcome
                title="You're signed in"
                icon={
                    <CircleCheck
                        className="size-6 text-status-ok"
                        aria-hidden="true"
                    />
                }
            >
                Go back to your terminal. You can revoke this sign-in any time
                in Settings → API tokens.
            </Outcome>
        );
    }

    if (login.status === 'denied') {
        return (
            <Outcome
                title="Sign-in denied"
                icon={
                    <CircleX
                        className="size-6 text-muted-foreground"
                        aria-hidden="true"
                    />
                }
            >
                Nothing was granted, and the terminal has stopped waiting.
            </Outcome>
        );
    }

    if (login.expired) {
        return (
            <Outcome
                title="This code expired"
                icon={
                    <Clock
                        className="size-6 text-muted-foreground"
                        aria-hidden="true"
                    />
                }
            >
                Run launchroom login again for a new one.
            </Outcome>
        );
    }

    return (
        <Approve
            login={login}
            teams={teams}
            lifetimes={lifetimes}
            defaultLifetime={defaultLifetime}
        />
    );
}

function EnterCode({ code }: { code: string | null }) {
    return (
        <>
            <Head title="CLI sign-in" />
            <AuthFrame
                title="Sign in to the launchroom CLI"
                description="Enter the code your terminal shows."
            >
                <Form
                    action={show().url}
                    method="get"
                    className="flex flex-col gap-4"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="code">Code</Label>
                        <Input
                            id="code"
                            name="code"
                            defaultValue={code ?? ''}
                            placeholder="WDJB-MJHT"
                            autoComplete="off"
                            autoCapitalize="characters"
                            className="text-center type-mono tracking-[0.2em] uppercase"
                            autoFocus
                        />
                        {code !== null && (
                            <InputError message="No sign-in is waiting for this code." />
                        )}
                    </div>
                    <Button type="submit">Continue</Button>
                </Form>
            </AuthFrame>
        </>
    );
}

function Approve({
    login,
    teams,
    lifetimes,
    defaultLifetime,
}: {
    login: App.Data.Api.CliLoginRequestData;
    teams: App.Data.Api.ApiTeamOptionData[];
    lifetimes: number[];
    defaultLifetime: number;
}) {
    const initial = teams.find((team) => team.current) ?? teams[0];
    const defaultsFor = (team: App.Data.Api.ApiTeamOptionData | undefined) =>
        (team?.abilities ?? [])
            .filter((option) => option.available && option.default)
            .map((option) => option.value);

    const form = useForm({
        team: initial?.id ?? '',
        abilities: defaultsFor(initial),
        expiresInDays: defaultLifetime,
    });
    const [denying, setDenying] = useState(false);
    const team = teams.find((entry) => entry.id === form.data.team);

    const toggle = (ability: App.Enums.ApiAbility, on: boolean) =>
        form.setData(
            'abilities',
            on
                ? [...form.data.abilities, ability]
                : form.data.abilities.filter((entry) => entry !== ability),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(approve(login.userCode).url);
    };

    const machine = login.hostname ?? 'Unknown machine';

    return (
        <>
            <Head title="CLI sign-in" />
            <AuthFrame
                title="Sign in to the launchroom CLI?"
                description="Only continue if this code matches your terminal."
                className="max-w-[440px]"
                footer={
                    <>
                        Requested <TimeAgo value={login.createdAt} />
                        {login.ip && <> from {login.ip}</>}
                    </>
                }
            >
                <form onSubmit={submit} className="flex flex-col gap-6">
                    <div className="flex flex-col items-center gap-4 rounded-xl border bg-card px-5 py-5 shadow-card">
                        <p
                            className="type-mono text-[28px] leading-none tracking-[0.18em]"
                            aria-label={`Code ${login.userCode.split('').join(' ')}`}
                        >
                            {login.userCode}
                        </p>
                        <div className="flex items-center gap-2 type-small text-muted-foreground">
                            <Laptop className="size-4" aria-hidden="true" />
                            <span>{machine}</span>
                            <MetaDivider />
                            <span>
                                {login.clientName}
                                {login.version && ` ${login.version}`}
                            </span>
                        </div>
                    </div>

                    {teams.length > 1 && (
                        <div className="grid gap-2">
                            <Label htmlFor="team">Team</Label>
                            <Select
                                value={form.data.team}
                                onValueChange={(value) => {
                                    const next = teams.find(
                                        (entry) => entry.id === value,
                                    );
                                    form.setData({
                                        ...form.data,
                                        team: value,
                                        abilities: defaultsFor(next),
                                    });
                                }}
                            >
                                <SelectTrigger id="team" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {teams.map((entry) => (
                                        <SelectItem
                                            key={entry.id}
                                            value={entry.id}
                                        >
                                            {entry.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.team} />
                        </div>
                    )}

                    <fieldset className="flex flex-col gap-3">
                        <legend className="mb-3 type-label">The CLI can</legend>
                        {(team?.abilities ?? []).map((option) => {
                            const id = `cli-ability-${option.value}`;
                            const always = option.value === 'read';

                            return (
                                <div
                                    key={option.value}
                                    className="flex items-start gap-3"
                                >
                                    <Checkbox
                                        id={id}
                                        checked={
                                            always ||
                                            form.data.abilities.includes(
                                                option.value,
                                            )
                                        }
                                        disabled={always || !option.available}
                                        onCheckedChange={(checked) =>
                                            toggle(
                                                option.value,
                                                checked === true,
                                            )
                                        }
                                        className="mt-0.5"
                                    />
                                    <label
                                        htmlFor={id}
                                        className="flex flex-col gap-0.5"
                                    >
                                        <span className="type-body font-medium">
                                            {option.label}
                                        </span>
                                        <span className="type-small text-muted-foreground">
                                            {option.description}
                                        </span>
                                    </label>
                                </div>
                            );
                        })}
                        <InputError message={form.errors.abilities} />
                    </fieldset>

                    <div className="grid gap-2">
                        <Label htmlFor="lifetime">Stays signed in for</Label>
                        <Select
                            value={String(form.data.expiresInDays)}
                            onValueChange={(value) =>
                                form.setData('expiresInDays', Number(value))
                            }
                        >
                            <SelectTrigger id="lifetime" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {lifetimes.map((days) => (
                                    <SelectItem key={days} value={String(days)}>
                                        {lifetimeLabel(days)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={form.processing || denying}
                            onClick={() => {
                                setDenying(true);
                                router.post(
                                    deny(login.userCode).url,
                                    {},
                                    { onFinish: () => setDenying(false) },
                                );
                            }}
                        >
                            Deny
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && !denying && <Spinner />}
                            Approve
                        </Button>
                    </div>
                </form>
            </AuthFrame>
        </>
    );
}
