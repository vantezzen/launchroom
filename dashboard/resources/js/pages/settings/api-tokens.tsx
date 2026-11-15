import { Head, Link, router } from '@inertiajs/react';
import { ArrowUpRight, KeyRound, Terminal, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { CopyValue } from '@/components/copy-value';
import InputError from '@/components/input-error';
import { ChoiceTiles } from '@/components/patterns/choice-tiles';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { EmptyState } from '@/components/patterns/empty-state';
import { MetaLine } from '@/components/patterns/meta-line';
import { TimeAgo } from '@/components/time-ago';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { requestJson } from '@/lib/http';
import { apiDocs } from '@/routes';
import { destroy, store } from '@/routes/api-tokens';
import {
    SettingsBody,
    SettingsCard,
    SettingsRow,
} from '@/components/settings/settings-card';
import {
    AbilityFields,
    defaultAbilities,
    lifetimeLabel,
} from '@/features/api-tokens/ability-fields';
import SettingsLayout from '@/features/settings/settings-layout';

type Props = {
    tokens: App.Data.Api.ApiTokenData[];
    teamOptions: App.Data.Api.ApiTeamOptionData[];
    lifetimes: number[];
    defaultLifetime: number;
    apiUrl: string;
    cliInstall: string;
};

const DAY = 24 * 60 * 60 * 1000;

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
    defaultLifetime,
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
                setting="cli"
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
                    description="For macOS and Linux. The script verifies the signed checksums."
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
                    description="Opens this dashboard so you can approve the sign-in, with no token to copy."
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
                setting="tokens"
                description="For scripts and CI. Each token acts as you in one team."
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
                    defaultLifetime={defaultLifetime}
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
                    <MetaLine>
                        {token.teamName}
                        {token.lastUsedAt ? (
                            <span>
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
                            </span>
                        ) : (
                            'Never used'
                        )}
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
                    </MetaLine>
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
    defaultLifetime,
    apiUrl,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    team: App.Data.Api.ApiTeamOptionData;
    lifetimes: number[];
    defaultLifetime: number;
    apiUrl: string;
}) {
    const defaults = defaultAbilities(team);
    const [name, setName] = useState('');
    const [days, setDays] = useState(String(defaultLifetime));
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

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            const result = await requestJson<App.Data.Api.CreatedApiTokenData>(
                'post',
                store().url,
                {
                    name: name.trim() === '' ? 'API token' : name.trim(),
                    expiresInDays: Number(days),
                    abilities,
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
                                It's shown only once. Store it in your CI's
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
                                It acts as you in {team.name} ({team.role}) and
                                can't do more than your role allows.
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

                        <AbilityFields
                            legend="It can"
                            idPrefix="ability"
                            team={team}
                            value={abilities}
                            onChange={setAbilities}
                            explainUnavailable
                        />

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

ApiTokens.layout = SettingsLayout;
