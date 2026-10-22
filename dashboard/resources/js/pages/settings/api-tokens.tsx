import { Head, Link, router } from '@inertiajs/react';
import { ArrowUpRight, KeyRound, Terminal, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { CopyValue } from '@/components/copy-value';
import InputError from '@/components/input-error';
import { ChoiceTiles } from '@/components/patterns/choice-tiles';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { EmptyState } from '@/components/patterns/empty-state';
import { TimeAgo } from '@/components/time-ago';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { api } from '@/features/variables/api';
import {
    SettingsBody,
    SettingsCard,
    SettingsRow,
} from '@/features/settings/settings-card';
import { apiDocs } from '@/routes';
import { destroy, store } from '@/routes/api-tokens';

type Props = {
    tokens: App.Data.Api.ApiTokenData[];
    teamOptions: App.Data.Api.ApiTeamOptionData[];
    lifetimes: number[];
    apiUrl: string;
    cliInstall: string;
};

const DAY = 24 * 60 * 60 * 1000;

function lifetimeLabel(days: number): string {
    return days === 365 ? '1 year' : `${days} days`;
}

function expiryLine(expiresAt: string | null): {
    text: string;
    soon: boolean;
} {
    if (expiresAt === null) {
        return { text: 'No expiry', soon: true };
    }

    const left = Math.ceil((new Date(expiresAt).getTime() - Date.now()) / DAY);

    if (left <= 0) {
        return { text: 'Expired', soon: true };
    }

    return {
        text: left === 1 ? 'Expires tomorrow' : `Expires in ${left} days`,
        soon: left <= 7,
    };
}

/**
 * Settings → API tokens (docs/16 §1.3): the CLI first, then your tokens.
 * A token is created in a dialog that turns into the show-once view; the
 * plaintext lives only in that dialog's state.
 */
export default function ApiTokens({
    tokens,
    teamOptions: teams,
    lifetimes,
    apiUrl,
    cliInstall,
}: Props) {
    const [creating, setCreating] = useState(false);
    const team = teams.find((entry) => entry.current) ?? teams[0] ?? null;

    return (
        <>
            <Head title="API tokens" />

            <SettingsCard
                title="Use the CLI"
                description="Deploy, follow logs and pull variables from your terminal."
                footer={
                    <Link
                        href={apiDocs()}
                        className="inline-flex items-center gap-0.5 text-signal underline decoration-signal/40 underline-offset-4 hover:decoration-signal"
                    >
                        API reference
                        <ArrowUpRight className="size-3.5" aria-hidden="true" />
                    </Link>
                }
            >
                <SettingsRow
                    label="Install"
                    description="macOS and Linux. Checks the signed checksums."
                    media={<IconTile icon={Terminal} />}
                >
                    <CopyValue
                        value={cliInstall}
                        label="install command"
                        className="w-full sm:w-96"
                    />
                </SettingsRow>
                <SettingsRow
                    label="Sign in"
                    description="Opens this dashboard to approve. No token to copy."
                    media={<IconTile icon={KeyRound} />}
                >
                    <CopyValue
                        value="launchroom login"
                        label="sign-in command"
                        className="w-full sm:w-96"
                    />
                </SettingsRow>
            </SettingsCard>

            <SettingsCard
                title="Your tokens"
                description="For scripts and CI. Each acts as you, in one team."
                actions={
                    team && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setCreating(true)}
                        >
                            Create token
                        </Button>
                    )
                }
            >
                {tokens.length === 0 ? (
                    <SettingsBody>
                        <EmptyState icon={KeyRound} variant="plain">
                            No tokens yet. Create one for a script, or run
                            launchroom login.
                        </EmptyState>
                    </SettingsBody>
                ) : (
                    tokens.map((token) => (
                        <TokenRow key={token.id} token={token} />
                    ))
                )}
            </SettingsCard>

            {team && (
                <CreateTokenDialog
                    open={creating}
                    onOpenChange={setCreating}
                    team={team}
                    lifetimes={lifetimes}
                    apiUrl={apiUrl}
                />
            )}
        </>
    );
}

function IconTile({ icon: Icon }: { icon: typeof KeyRound }) {
    return (
        <span className="flex size-9 items-center justify-center rounded-lg bg-recessed">
            <Icon className="size-4 text-muted-foreground" aria-hidden="true" />
        </span>
    );
}

function TokenRow({ token }: { token: App.Data.Api.ApiTokenData }) {
    const expiry = expiryLine(token.expiresAt);

    return (
        <SettingsRow
            align="start"
            label={
                <span className="flex flex-wrap items-center gap-2">
                    {token.name}
                    {token.client === 'cli' && (
                        <Badge variant="outline">CLI</Badge>
                    )}
                </span>
            }
            description={
                <span className="flex flex-col gap-2">
                    <span>
                        {token.teamName} ·{' '}
                        {token.lastUsedAt ? (
                            <>
                                Last used <TimeAgo value={token.lastUsedAt} />
                                {token.lastUsedIp && (
                                    <>
                                        {' '}
                                        from{' '}
                                        <span className="type-mono-small">
                                            {token.lastUsedIp}
                                        </span>
                                    </>
                                )}
                            </>
                        ) : (
                            'Never used'
                        )}{' '}
                        ·{' '}
                        <span
                            className={
                                expiry.soon ? 'text-status-progress' : undefined
                            }
                        >
                            {expiry.soon && (
                                <TriangleAlert
                                    className="mr-1 inline size-3.5 align-[-2px]"
                                    aria-hidden="true"
                                />
                            )}
                            {expiry.text}
                        </span>
                    </span>
                    <span className="flex flex-wrap gap-1.5">
                        {token.abilities.map((ability) => (
                            <Badge key={ability} variant="secondary">
                                {ability}
                            </Badge>
                        ))}
                    </span>
                </span>
            }
        >
            <ConfirmPopover
                title={`Revoke ${token.name}?`}
                consequence="Scripts and terminals using it stop working right away."
                confirmLabel="Revoke"
                tone="destructive"
                onConfirm={() =>
                    new Promise<void>((resolve) =>
                        router.delete(destroy(token.id).url, {
                            preserveScroll: true,
                            onFinish: () => resolve(),
                        }),
                    )
                }
            >
                <Button variant="destructive" size="sm">
                    Revoke
                </Button>
            </ConfirmPopover>
        </SettingsRow>
    );
}

function CreateTokenDialog({
    open,
    onOpenChange,
    team,
    lifetimes,
    apiUrl,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    team: App.Data.Api.ApiTeamOptionData;
    lifetimes: number[];
    apiUrl: string;
}) {
    const defaults = team.abilities
        .filter(
            (option) =>
                option.available && option.default && option.value !== 'read',
        )
        .map((option) => option.value);
    const [name, setName] = useState('');
    const [days, setDays] = useState(
        String(lifetimes.includes(90) ? 90 : lifetimes[0]),
    );
    const [abilities, setAbilities] =
        useState<App.Enums.ApiAbility[]>(defaults);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [created, setCreated] =
        useState<App.Data.Api.CreatedApiTokenData | null>(null);

    const reset = () => {
        setName('');
        setAbilities(defaults);
        setError(null);
        setCreated(null);
    };

    const close = (next: boolean) => {
        onOpenChange(next);

        if (!next) {
            if (created) {
                router.reload({ only: ['tokens'] });
            }

            reset();
        }
    };

    const toggle = (ability: App.Enums.ApiAbility, on: boolean) =>
        setAbilities((current) =>
            on
                ? [...current, ability]
                : current.filter((entry) => entry !== ability),
        );

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            const result = await api<App.Data.Api.CreatedApiTokenData>(
                'post',
                store().url,
                {
                    name: name.trim() === '' ? 'API token' : name.trim(),
                    expiresInDays: Number(days),
                    abilities: ['read', ...abilities],
                },
            );
            setCreated(result);
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'Could not create the token.',
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="sm:max-w-lg">
                {created ? (
                    <div className="flex flex-col gap-5">
                        <div className="flex flex-col gap-1.5">
                            <DialogTitle>Copy your token</DialogTitle>
                            <DialogDescription>
                                You won't see it again. Store it in your CI's
                                secrets.
                            </DialogDescription>
                        </div>
                        <CopyValue value={created.plainText} label="token" />
                        <div className="flex flex-col gap-2">
                            <p className="type-small text-muted-foreground">
                                Try it
                            </p>
                            <CopyValue
                                value={`curl -H "Authorization: Bearer $LAUNCHROOM_TOKEN" ${apiUrl}/me`}
                                label="example request"
                            />
                        </div>
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button>Done</Button>
                            </DialogClose>
                        </DialogFooter>
                    </div>
                ) : (
                    <form onSubmit={submit} className="flex flex-col gap-6">
                        <div className="flex flex-col gap-1.5">
                            <DialogTitle>Create token</DialogTitle>
                            <DialogDescription>
                                It acts as you in {team.name} ({team.role}),
                                never with more than your role allows.
                            </DialogDescription>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="token-name">Name</Label>
                            <Input
                                id="token-name"
                                value={name}
                                maxLength={80}
                                placeholder="GitHub Actions"
                                autoFocus
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                            />
                        </div>

                        <div className="grid gap-2">
                            <span className="type-label">Expires after</span>
                            <ChoiceTiles
                                label="Expires after"
                                columns={4}
                                value={days}
                                onChange={setDays}
                                options={lifetimes.map((value) => ({
                                    value: String(value),
                                    label: lifetimeLabel(value),
                                }))}
                                className="[&_label]:min-h-14"
                            />
                        </div>

                        <fieldset className="flex flex-col gap-3">
                            <legend className="mb-3 type-label">It can</legend>
                            {team.abilities.map((option) => {
                                const id = `ability-${option.value}`;
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
                                                abilities.includes(option.value)
                                            }
                                            disabled={
                                                always || !option.available
                                            }
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
                                                {!option.available && (
                                                    <span className="font-normal text-muted-foreground">
                                                        {' '}
                                                        · not with your role
                                                    </span>
                                                )}
                                            </span>
                                            <span className="type-small text-muted-foreground">
                                                {option.description}
                                            </span>
                                        </label>
                                    </div>
                                );
                            })}
                        </fieldset>

                        <InputError message={error ?? undefined} />

                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="ghost">
                                    Cancel
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={busy}>
                                {busy && <Spinner />}
                                Create token
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
