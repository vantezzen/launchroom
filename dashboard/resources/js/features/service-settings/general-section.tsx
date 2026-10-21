import { useForm } from '@inertiajs/react';
import {
    Network,
    ArrowRight,
    Check,
    Copy,
    Globe,
    HardDrive,
    Layers,
} from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ServiceGlyph, glyphLabel } from '@/features/canvas/service-glyph';
import { SettingsCard, SettingsRow } from '@/features/settings/settings-card';
import { useClipboard } from '@/hooks/use-clipboard';
import services from '@/routes/projects/services';
import { SaveRow, SettingsPage } from './section';
import type { SectionId } from './sections';
import type { SettingsContext } from './settings-context';
import type { Navigate } from './settings-tab';

export function CopyButton({ value, label }: { value: string; label: string }) {
    const [copied, copy] = useClipboard();

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            className="text-muted-foreground"
            aria-label={copied === value ? 'Copied' : `Copy ${label}`}
            onClick={() => void copy(value)}
        >
            {copied === value ? <Check /> : <Copy />}
        </Button>
    );
}

function MediaTile({ children }: { children: ReactNode }) {
    return (
        <span
            aria-hidden="true"
            className="flex size-8 shrink-0 items-center justify-center rounded-lg border bg-card text-muted-foreground [&_svg]:size-4"
        >
            {children}
        </span>
    );
}

const kindLabels: Record<App.Enums.ServiceKind, string> = {
    app: 'App',
    image: 'Image',
    worker: 'Worker',
    cron: 'Cron job',
    database: 'Database',
};

type Glance = {
    section: SectionId;
    label: string;
    media: ReactNode;
    value: string;
    detail?: string;
    mono?: boolean;
};

function glances(context: SettingsContext): Glance[] {
    const { settings, node } = context;
    const items: Glance[] = [];
    const web = node.kind === 'app' || node.kind === 'image';

    if (web) {
        const primary =
            settings.domains.find((domain) => domain.kind === 'custom') ??
            settings.domains[0];
        items.push({
            section: 'domains',
            label: 'Domains',
            media: (
                <MediaTile>
                    <Globe />
                </MediaTile>
            ),
            value: primary?.hostname ?? 'No domain yet',
            detail:
                settings.domains.length > 1
                    ? `and ${settings.domains.length - 1} more`
                    : primary
                      ? primary.kind === 'custom'
                          ? 'Custom domain'
                          : 'Generated for you'
                      : 'Add your own in a minute',
            mono: primary !== undefined,
        });
    }

    if (node.kind === 'app') {
        const plan = settings.detected;
        items.push({
            section: 'build',
            label: 'Build',
            media: <ServiceGlyph logo={node.logo} size="md" />,
            value: plan
                ? [plan.framework, plan.frameworkVersion]
                      .filter(Boolean)
                      .join(' ') ||
                  plan.runtime?.replace(/^(\w+) (\d+).*$/, '$1 $2') ||
                  'Detected'
                : 'Detected on the first build',
            detail: plan
                ? `Detected · ${[plan.packageManager, plan.port !== null ? `port ${plan.port}` : null].filter(Boolean).join(' · ')}`
                : undefined,
        });
    } else {
        items.push({
            section: 'source',
            label: 'Source',
            media: <ServiceGlyph logo={node.logo} size="md" />,
            value: settings.source.image ?? node.subtitle,
            detail: glyphLabel(node.logo),
            mono: settings.source.type === 'image',
        });
    }

    const placement = settings.placement;
    const planned = (placement?.servers ?? []).filter(
        (server) => server.planned > 0,
    );
    items.push({
        section: 'scaling',
        label: 'Scaling',
        media: (
            <MediaTile>
                <Layers />
            </MediaTile>
        ),
        value:
            node.kind === 'database'
                ? 'One replica'
                : `${settings.scaling.replicas} ${settings.scaling.replicas === 1 ? 'replica' : 'replicas'}`,
        detail:
            planned.length > 0
                ? `on ${planned.map((server) => server.name).join(', ')}`
                : `${settings.scaling.cpus} CPU · ${settings.scaling.memoryMb} MB each`,
    });

    const port = settings.deploy.port ?? settings.detected?.port ?? null;
    const address =
        node.privateAddress ??
        (port !== null
            ? `${web ? 'http://' : ''}${settings.privateHost}:${port}`
            : null);

    if (settings.volumes.length === 0 && address !== null) {
        items.push({
            section: 'networking',
            label: 'Private network',
            media: (
                <MediaTile>
                    <Network />
                </MediaTile>
            ),
            value: address,
            detail: 'For your other services',
            mono: true,
        });
    }

    if (settings.volumes.length > 0) {
        items.push({
            section: 'volumes',
            label: 'Volumes',
            media: (
                <MediaTile>
                    <HardDrive />
                </MediaTile>
            ),
            value: settings.volumes[0].mountPath,
            detail:
                settings.volumes[0].server !== null
                    ? `on ${settings.volumes[0].server}`
                    : 'Created with the next deploy',
            mono: true,
        });
    }

    return items;
}

/**
 * General: the service at a glance (one tile per area that matters for
 * this kind, each opening its page), then the name and the slug.
 */
export function GeneralSection({
    context,
    navigate,
}: {
    context: SettingsContext;
    navigate: Navigate;
}) {
    const { node, canUpdate } = context;
    const form = useForm({ name: node.name });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(services.rename(context.route).url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => form.setDefaults(),
        });
    };

    return (
        <SettingsPage
            title="General"
            description={`${kindLabels[node.kind]} · ${node.subtitle}`}
        >
            <ul className="grid gap-3 @xl/settings:grid-cols-2">
                {glances(context).map((item) => (
                    <li key={item.section}>
                        <button
                            type="button"
                            onClick={() => navigate(item.section)}
                            className="group flex h-full w-full flex-col gap-3 rounded-xl border bg-card p-4 text-left shadow-card transition-colors duration-fast hover:border-border-strong focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <span className="flex items-center justify-between gap-2 type-label text-muted-foreground">
                                {item.label}
                                <ArrowRight
                                    aria-hidden="true"
                                    className="size-4 text-faint transition-transform duration-fast group-hover:translate-x-0.5 group-hover:text-foreground"
                                />
                            </span>
                            <span className="flex min-w-0 items-center gap-3">
                                {item.media}
                                <span className="flex min-w-0 flex-col">
                                    <span
                                        className={
                                            item.mono
                                                ? 'truncate type-mono font-medium'
                                                : 'truncate type-heading'
                                        }
                                    >
                                        {item.value}
                                    </span>
                                    {item.detail && (
                                        <span className="truncate type-small text-muted-foreground">
                                            {item.detail}
                                        </span>
                                    )}
                                </span>
                            </span>
                        </button>
                    </li>
                ))}
            </ul>

            <form onSubmit={submit}>
                <SettingsCard title="Service">
                    <SettingsRow
                        setting="name"
                        label="Name"
                        description="Shown in every environment of this project."
                        htmlFor="service-name"
                    >
                        <div className="flex w-full flex-col gap-1 sm:w-72">
                            <Input
                                id="service-name"
                                value={form.data.name}
                                maxLength={63}
                                required
                                disabled={!canUpdate}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                            <InputError message={form.errors.name} />
                        </div>
                    </SettingsRow>
                    <SettingsRow
                        setting="slug"
                        label="Slug"
                        description="Its private hostname and part of generated domains, so it never changes."
                    >
                        <span className="type-mono text-muted-foreground">
                            {node.slug}
                        </span>
                        <CopyButton value={node.slug} label="slug" />
                    </SettingsRow>
                </SettingsCard>
                {canUpdate && (
                    <SaveRow
                        dirty={form.isDirty}
                        processing={form.processing}
                        onReset={() => form.reset()}
                    />
                )}
            </form>
        </SettingsPage>
    );
}
