import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Logo } from '@/components/logo';
import { ChoiceTiles } from '@/components/patterns/choice-tiles';
import type { ChoiceTile } from '@/components/patterns/choice-tiles';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store } from '@/routes/notifications/channels';

type Kind = App.Enums.Alerts.AlertChannelKind;

export const CHANNEL_KINDS: ChoiceTile<Kind>[] = [
    {
        value: 'email',
        label: 'Email',
        description: 'To you or your team',
        media: <Logo kind="email" size={32} decorative />,
    },
    {
        value: 'slack',
        label: 'Slack',
        description: 'A channel you pick',
        media: <Logo kind="slack" size={32} decorative />,
    },
    {
        value: 'discord',
        label: 'Discord',
        description: 'A channel on your server',
        media: <Logo kind="discord" size={32} decorative />,
    },
    {
        value: 'webhook',
        label: 'Webhook',
        description: 'Signed JSON to any URL',
        media: <Logo kind="webhook" size={32} decorative />,
    },
];

const URL_FIELD: Record<
    Exclude<Kind, 'email'>,
    { label: string; placeholder: string; hint: string }
> = {
    slack: {
        label: 'Incoming webhook URL',
        placeholder: 'https://hooks.slack.com/services/…',
        hint: 'In Slack: Apps → Incoming Webhooks → Add to a channel, then copy the URL.',
    },
    discord: {
        label: 'Webhook URL',
        placeholder: 'https://discord.com/api/webhooks/…',
        hint: 'In Discord: channel settings → Integrations → Webhooks → New webhook.',
    },
    webhook: {
        label: 'URL',
        placeholder: 'https://example.com/hooks/launchroom',
        hint: 'Every request is signed. You see the signing secret once, right after adding it.',
    },
};

/**
 * "Where should we tell you?" (docs/15 §3.6): the four kinds as tiles,
 * then the one field the chosen kind needs. Adding keeps the page; the new
 * channel's row offers "Send a test" next.
 */
export function AddChannel({
    defaultEmail,
    mailConfigured,
    onAdded,
    kind,
    onKindChange,
}: {
    defaultEmail: string;
    mailConfigured: boolean;
    onAdded?: () => void;
    kind: Kind | null;
    onKindChange: (kind: Kind) => void;
}) {
    const form = useForm<{
        kind: Kind | null;
        recipients: string;
        secretUrl: string;
    }>({ kind, recipients: defaultEmail, secretUrl: '' });

    const choose = (next: Kind) => {
        onKindChange(next);
        form.setData('kind', next);
        form.clearErrors();
    };

    const submit = () => {
        form.transform((data) => ({
            kind: data.kind,
            ...(data.kind === 'email'
                ? {
                      recipients: data.recipients
                          .split(/[\s,;]+/)
                          .map((address) => address.trim())
                          .filter(Boolean),
                  }
                : { secretUrl: data.secretUrl }),
        }));
        form.post(store().url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('secretUrl');
                onAdded?.();
            },
        });
    };

    const errors = form.errors as Record<string, string | undefined>;
    const recipientsError =
        errors.recipients ??
        Object.entries(errors).find(([key]) =>
            key.startsWith('recipients.'),
        )?.[1];

    return (
        <div className="flex flex-col gap-6">
            <ChoiceTiles<Kind>
                label="Where should we tell you?"
                options={CHANNEL_KINDS}
                value={kind}
                onChange={choose}
                columns={4}
            />

            {kind !== null && (
                <form
                    className="flex animate-enter flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    {kind === 'email' ? (
                        <div className="grid gap-2">
                            <Label htmlFor="alert-recipients">
                                Email addresses
                            </Label>
                            <Input
                                id="alert-recipients"
                                type="text"
                                inputMode="email"
                                autoComplete="email"
                                value={form.data.recipients}
                                onChange={(event) =>
                                    form.setData(
                                        'recipients',
                                        event.target.value,
                                    )
                                }
                            />
                            <p className="type-small text-muted-foreground">
                                {mailConfigured
                                    ? 'Separate several with commas. Up to 10.'
                                    : 'This launchroom has no mail server set up yet, so emails stay unsent until MAIL_* is configured.'}
                            </p>
                            <InputError message={recipientsError} />
                        </div>
                    ) : (
                        <div className="grid gap-2">
                            <Label htmlFor="alert-url">
                                {URL_FIELD[kind].label}
                            </Label>
                            <Input
                                id="alert-url"
                                type="url"
                                autoComplete="off"
                                spellCheck={false}
                                className="font-mono text-[13px]"
                                placeholder={URL_FIELD[kind].placeholder}
                                value={form.data.secretUrl}
                                onChange={(event) =>
                                    form.setData(
                                        'secretUrl',
                                        event.target.value,
                                    )
                                }
                            />
                            <p className="type-small text-muted-foreground">
                                {URL_FIELD[kind].hint}
                            </p>
                            <InputError message={errors.secretUrl} />
                        </div>
                    )}
                    <InputError message={errors.kind} />
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            {kind === 'email'
                                ? 'Send alerts by email'
                                : `Add ${CHANNEL_KINDS.find((k) => k.value === kind)?.label}`}
                        </Button>
                    </div>
                </form>
            )}
        </div>
    );
}
