import { Head, router, useForm } from '@inertiajs/react';
import { FileCode2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { SharingNotice } from '@/features/dockerfile-generation/generation-sheet';
import {
    SettingsBody,
    SettingsCard,
    SettingsRow,
} from '@/features/settings/settings-card';
import {
    edit,
    team as teamRoute,
    update,
} from '@/routes/dockerfile-generation';
import { edit as teamSettings } from '@/routes/team';

type Props = {
    provider: App.Data.Dockerfiles.AiProviderSettingsData;
    team: { name: string; enabled: boolean; canChange: boolean };
    limits: {
        files: number;
        fileKb: number;
        totalKb: number;
        listing: number;
        repairs: number;
    };
};

/**
 * Settings → Dockerfile generation (docs/20 §1, §7): the instance's model
 * provider (owners) and the team's switch. Both are off until someone turns
 * them on; the page says plainly what is shared.
 */
export default function DockerfileGenerationSettings({
    provider,
    team,
    limits,
}: Props) {
    const [custom, setCustom] = useState(
        !provider.suggestedModels.includes(provider.model),
    );
    const form = useForm<{
        enabled: boolean;
        model: string;
        apiKey: string;
        removeKey: boolean;
    }>({
        enabled: provider.enabled,
        model: provider.model,
        apiKey: '',
        removeKey: false,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(update().url, {
            preserveScroll: true,
            onSuccess: () => {
                form.setData('apiKey', '');
                form.setDefaults({
                    ...form.data,
                    apiKey: '',
                    removeKey: false,
                });
            },
        });
    };

    const keyReady =
        provider.hasKey || provider.stub || form.data.apiKey !== '';

    return (
        <>
            <Head title="Dockerfile generation" />

            <SettingsCard
                title="Model provider"
                description="When launchroom can't detect how to build an app, it can suggest a Dockerfile and verify it with a real build."
                footer={
                    provider.canManage
                        ? undefined
                        : 'Only an owner of this launchroom can change the provider.'
                }
            >
                <form onSubmit={submit} className="contents">
                    <SettingsRow
                        media={
                            <span className="flex size-8 items-center justify-center rounded-lg border bg-recessed text-muted-foreground">
                                <FileCode2
                                    aria-hidden="true"
                                    className="size-4"
                                />
                            </span>
                        }
                        label="Generate Dockerfiles"
                        description={
                            provider.enabled
                                ? `On, with Anthropic's ${provider.providerLabel}.`
                                : 'Off. Nothing is sent anywhere until you turn it on.'
                        }
                        inline
                    >
                        <Switch
                            checked={form.data.enabled}
                            disabled={
                                !provider.canManage ||
                                (!keyReady && !form.data.enabled)
                            }
                            onCheckedChange={(enabled) =>
                                form.setData('enabled', enabled)
                            }
                            aria-label="Generate Dockerfiles"
                        />
                    </SettingsRow>
                    <SettingsRow
                        label="Model"
                        description="The most capable model gives the best first drafts; a smaller one costs less."
                        htmlFor="dockerfile-model"
                    >
                        <div className="flex w-full flex-col gap-2 sm:w-72">
                            <div
                                className="flex flex-wrap gap-1.5"
                                role="radiogroup"
                                aria-label="Suggested models"
                            >
                                {provider.suggestedModels.map((model) => (
                                    <button
                                        key={model}
                                        type="button"
                                        role="radio"
                                        aria-checked={
                                            !custom && form.data.model === model
                                        }
                                        disabled={!provider.canManage}
                                        onClick={() => {
                                            setCustom(false);
                                            form.setData('model', model);
                                        }}
                                        className={
                                            !custom && form.data.model === model
                                                ? 'h-7 rounded-md border border-foreground bg-card px-2 type-mono-small'
                                                : 'h-7 rounded-md border px-2 type-mono-small text-muted-foreground hover:bg-hover'
                                        }
                                    >
                                        {model}
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked={custom}
                                    disabled={!provider.canManage}
                                    onClick={() => setCustom(true)}
                                    className={
                                        custom
                                            ? 'h-7 rounded-md border border-foreground bg-card px-2 type-small'
                                            : 'h-7 rounded-md border px-2 type-small text-muted-foreground hover:bg-hover'
                                    }
                                >
                                    Other
                                </button>
                            </div>
                            {custom && (
                                <Input
                                    id="dockerfile-model"
                                    value={form.data.model}
                                    disabled={!provider.canManage}
                                    placeholder={provider.defaultModel}
                                    className="font-mono text-[13px]"
                                    onChange={(event) =>
                                        form.setData(
                                            'model',
                                            event.target.value.trim(),
                                        )
                                    }
                                />
                            )}
                            <InputError message={form.errors.model} />
                        </div>
                    </SettingsRow>
                    <SettingsRow
                        label="API key"
                        description={
                            provider.stub
                                ? 'Development: launchroom answers with a stub, no key needed.'
                                : provider.hasKey
                                  ? `Key ending in …${provider.keyLast4 ?? '····'}, encrypted. Paste a new one to replace it.`
                                  : 'From console.anthropic.com. Stored encrypted; launchroom never shows it again.'
                        }
                        htmlFor="dockerfile-key"
                    >
                        <div className="flex w-full flex-col gap-1 sm:w-72">
                            <Input
                                id="dockerfile-key"
                                type="password"
                                autoComplete="off"
                                value={form.data.apiKey}
                                disabled={!provider.canManage}
                                placeholder={
                                    provider.hasKey
                                        ? '••••••••••••'
                                        : 'sk-ant-…'
                                }
                                className="font-mono text-[13px]"
                                onChange={(event) =>
                                    form.setData('apiKey', event.target.value)
                                }
                            />
                            <InputError message={form.errors.apiKey} />
                            {provider.hasKey && provider.keySetAt && (
                                <span className="type-small text-muted-foreground">
                                    Set <TimeAgo value={provider.keySetAt} />
                                </span>
                            )}
                        </div>
                    </SettingsRow>
                    {provider.canManage && (
                        <SettingsBody>
                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    type="submit"
                                    disabled={form.processing || !form.isDirty}
                                >
                                    Save
                                </Button>
                                {provider.hasKey && (
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        disabled={form.processing}
                                        onClick={() =>
                                            router.put(
                                                update().url,
                                                {
                                                    removeKey: true,
                                                    enabled: false,
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Remove key
                                    </Button>
                                )}
                            </div>
                        </SettingsBody>
                    )}
                </form>
            </SettingsCard>

            <SettingsCard
                title={`For ${team.name}`}
                description="Each team decides whether it uses Dockerfile generation."
            >
                <SettingsRow
                    label="Offer “Generate a Dockerfile”"
                    description={
                        provider.enabled
                            ? 'Shown when launchroom can’t detect how to build an app, and in Settings → Build.'
                            : 'Works once the model provider above is on.'
                    }
                    inline
                >
                    <Switch
                        checked={team.enabled}
                        disabled={!team.canChange}
                        onCheckedChange={(enabled) =>
                            router.put(
                                teamRoute().url,
                                { enabled },
                                { preserveScroll: true },
                            )
                        }
                        aria-label="Offer Generate a Dockerfile"
                    />
                </SettingsRow>
            </SettingsCard>

            <SettingsCard
                title="What launchroom sends"
                description={`At most ${limits.files} files (${limits.fileKb} KB each, ${limits.totalKb} KB together) and a list of up to ${limits.listing} paths, per try.`}
                footer={`Every Dockerfile is linted, built and started before you see it, with up to ${limits.repairs} repairs that send back the masked build log.`}
            >
                <SettingsBody>
                    <SharingNotice
                        providerLabel={provider.providerLabel}
                        model={form.data.model || provider.model}
                    />
                </SettingsBody>
            </SettingsCard>
        </>
    );
}

DockerfileGenerationSettings.layout = {
    breadcrumbs: [
        { title: 'Settings', href: teamSettings() },
        { title: 'Dockerfile generation', href: edit() },
    ],
};
