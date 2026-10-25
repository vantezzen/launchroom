import { useForm } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { CopyValue } from '@/components/copy-value';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/git/connections';

type Provider = 'gitlab' | 'gitea';

export type ConnectPreset = {
    baseUrl?: string;
    allowPrivateNetwork?: boolean;
    method?: 'oauth' | 'token';
};

/** A numbered step of a guided flow: number, title, body. */
export function Step({
    number,
    title,
    children,
}: {
    number: number;
    title: string;
    children: ReactNode;
}) {
    return (
        <li className="grid grid-cols-[1.75rem_1fr] gap-x-3 gap-y-3">
            <span
                aria-hidden="true"
                className="flex size-7 items-center justify-center rounded-full border bg-card type-label text-muted-foreground"
            >
                {number}
            </span>
            <h3 className="self-center type-label">{title}</h3>
            <div className="col-start-2 flex flex-col gap-3">{children}</div>
        </li>
    );
}

function origin(raw: string): string | null {
    try {
        const url = new URL(raw.trim());

        return url.protocol === 'https:' || url.protocol === 'http:'
            ? url.origin
            : null;
    } catch {
        return null;
    }
}

/**
 * Connect GitLab or Gitea/Forgejo (docs/19 §4.1): where it is, an OAuth
 * application on that server (or an access token), then the provider's own
 * sign-in. OAuth leaves the dashboard for the authorize page and comes back.
 */
export function ConnectProviderForm({
    provider,
    gitlabUrl,
    oauthCallbackUrl,
    canAllowPrivateNetwork,
    preset,
}: {
    provider: Provider;
    gitlabUrl: string;
    oauthCallbackUrl: string;
    canAllowPrivateNetwork: boolean;
    preset?: ConnectPreset;
}) {
    const isGitLab = provider === 'gitlab';
    const name = isGitLab ? 'GitLab' : 'Gitea or Forgejo';
    const presetSelfManaged =
        preset?.baseUrl !== undefined && preset.baseUrl !== gitlabUrl;
    const [selfManaged, setSelfManaged] = useState(
        !isGitLab || presetSelfManaged,
    );

    const form = useForm({
        provider,
        baseUrl: preset?.baseUrl ?? (isGitLab ? gitlabUrl : ''),
        method: preset?.method ?? ('oauth' as 'oauth' | 'token'),
        clientId: '',
        clientSecret: '',
        token: '',
        allowPrivateNetwork: preset?.allowPrivateNetwork ?? false,
    });

    const base = origin(form.data.baseUrl);
    const oauth = form.data.method === 'oauth';
    const applicationsUrl =
        base === null
            ? null
            : isGitLab
              ? `${base}/-/user_settings/applications`
              : `${base}/user/settings/applications`;
    const tokensUrl =
        base === null
            ? null
            : isGitLab
              ? `${base}/-/user_settings/personal_access_tokens`
              : `${base}/user/settings/applications`;
    const host = base === null ? name : new URL(base).host;

    const submit = () => {
        form.transform((data) =>
            data.method === 'oauth'
                ? { ...data, token: undefined }
                : { ...data, clientId: undefined, clientSecret: undefined },
        );
        form.post(store().url, { preserveScroll: true });
    };

    return (
        <form
            className="flex animate-enter flex-col gap-6"
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <ol className="flex flex-col gap-7">
                <Step number={1} title={`Where is your ${name}?`}>
                    {isGitLab && (
                        <div
                            role="radiogroup"
                            aria-label="GitLab server"
                            className="flex flex-wrap gap-2"
                        >
                            {[
                                { value: false, label: 'gitlab.com' },
                                { value: true, label: 'My own GitLab' },
                            ].map((option) => (
                                <Button
                                    key={option.label}
                                    type="button"
                                    role="radio"
                                    aria-checked={selfManaged === option.value}
                                    variant={
                                        selfManaged === option.value
                                            ? 'default'
                                            : 'outline'
                                    }
                                    size="sm"
                                    onClick={() => {
                                        setSelfManaged(option.value);
                                        form.setData(
                                            'baseUrl',
                                            option.value ? '' : gitlabUrl,
                                        );
                                    }}
                                >
                                    {option.label}
                                </Button>
                            ))}
                        </div>
                    )}
                    {selfManaged && (
                        <div className="grid max-w-md gap-2">
                            <Label htmlFor={`${provider}-url`}>
                                Web address
                            </Label>
                            <Input
                                id={`${provider}-url`}
                                type="url"
                                inputMode="url"
                                autoComplete="off"
                                spellCheck={false}
                                className="type-mono"
                                placeholder={
                                    isGitLab
                                        ? 'https://gitlab.example.com'
                                        : 'https://git.example.com'
                                }
                                value={form.data.baseUrl}
                                onChange={(event) =>
                                    form.setData('baseUrl', event.target.value)
                                }
                            />
                            <InputError message={form.errors.baseUrl} />
                        </div>
                    )}
                    {!selfManaged && (
                        <InputError message={form.errors.baseUrl} />
                    )}
                    {canAllowPrivateNetwork && selfManaged && (
                        <div className="flex items-start gap-2">
                            <Checkbox
                                id={`${provider}-private`}
                                checked={form.data.allowPrivateNetwork}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'allowPrivateNetwork',
                                        checked === true,
                                    )
                                }
                            />
                            <div className="grid gap-1">
                                <Label htmlFor={`${provider}-private`}>
                                    This server is on my private network
                                </Label>
                                <p className="type-small text-muted-foreground">
                                    Allows LAN addresses and plain http. Only
                                    instance owners can turn this on, and the
                                    change is recorded in the audit log.
                                </p>
                                <InputError
                                    message={form.errors.allowPrivateNetwork}
                                />
                            </div>
                        </div>
                    )}
                </Step>

                {oauth ? (
                    <Step number={2} title={`Create an application on ${host}`}>
                        <p className="max-w-prose type-body text-muted-foreground">
                            {isGitLab
                                ? 'Name it launchroom, paste the redirect URL, keep Confidential on and tick the api scope.'
                                : 'Under OAuth2 Applications, name it launchroom and paste the redirect URL. Keep it confidential.'}
                        </p>
                        <div className="grid max-w-xl gap-2">
                            <Label>Redirect URL</Label>
                            <CopyValue
                                value={oauthCallbackUrl}
                                label="redirect URL"
                            />
                        </div>
                        {applicationsUrl !== null && (
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    asChild
                                >
                                    <a
                                        href={applicationsUrl}
                                        target="_blank"
                                        rel="noreferrer noopener"
                                    >
                                        Open applications on {host}
                                        <ArrowUpRight aria-hidden="true" />
                                    </a>
                                </Button>
                            </div>
                        )}
                        <div className="grid max-w-xl gap-4 sm:grid-cols-2">
                            <div className="grid content-start gap-2">
                                <Label htmlFor={`${provider}-client-id`}>
                                    Application ID
                                </Label>
                                <Input
                                    id={`${provider}-client-id`}
                                    autoComplete="off"
                                    spellCheck={false}
                                    className="type-mono"
                                    value={form.data.clientId}
                                    onChange={(event) =>
                                        form.setData(
                                            'clientId',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError message={form.errors.clientId} />
                            </div>
                            <div className="grid content-start gap-2">
                                <Label htmlFor={`${provider}-client-secret`}>
                                    Secret
                                </Label>
                                <Input
                                    id={`${provider}-client-secret`}
                                    type="password"
                                    autoComplete="off"
                                    spellCheck={false}
                                    className="type-mono"
                                    value={form.data.clientSecret}
                                    onChange={(event) =>
                                        form.setData(
                                            'clientSecret',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.clientSecret}
                                />
                            </div>
                        </div>
                    </Step>
                ) : (
                    <Step
                        number={2}
                        title={`Create an access token on ${host}`}
                    >
                        <p className="max-w-prose type-body text-muted-foreground">
                            {isGitLab
                                ? 'A personal, group or project access token with the api scope.'
                                : 'A token with the write:repository and read:user scopes.'}{' '}
                            launchroom stores it encrypted and never shows it
                            again.
                        </p>
                        {tokensUrl !== null && (
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    asChild
                                >
                                    <a
                                        href={tokensUrl}
                                        target="_blank"
                                        rel="noreferrer noopener"
                                    >
                                        Open tokens on {host}
                                        <ArrowUpRight aria-hidden="true" />
                                    </a>
                                </Button>
                            </div>
                        )}
                        <div className="grid max-w-md gap-2">
                            <Label htmlFor={`${provider}-token`}>
                                Access token
                            </Label>
                            <Input
                                id={`${provider}-token`}
                                type="password"
                                autoComplete="off"
                                spellCheck={false}
                                className="type-mono"
                                value={form.data.token}
                                onChange={(event) =>
                                    form.setData('token', event.target.value)
                                }
                            />
                            <InputError message={form.errors.token} />
                        </div>
                    </Step>
                )}

                <Step
                    number={3}
                    title={
                        oauth
                            ? `Sign in with ${isGitLab ? 'GitLab' : host}`
                            : 'Connect'
                    }
                >
                    <p className="max-w-prose type-body text-muted-foreground">
                        {oauth
                            ? `You’ll approve launchroom on ${host} and return here. Your repositories then appear when you import a project.`
                            : 'launchroom checks the token and lists your repositories when you import a project.'}
                    </p>
                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {oauth
                                ? `Continue to ${isGitLab ? 'GitLab' : host}`
                                : `Connect ${isGitLab ? 'GitLab' : host}`}
                        </Button>
                        <Button
                            type="button"
                            variant="link"
                            className="px-0 text-muted-foreground"
                            onClick={() => {
                                form.clearErrors();
                                form.setData(
                                    'method',
                                    oauth ? 'token' : 'oauth',
                                );
                            }}
                        >
                            {oauth
                                ? 'Use an access token instead'
                                : 'Use an application instead'}
                        </Button>
                    </div>
                </Step>
            </ol>
        </form>
    );
}
