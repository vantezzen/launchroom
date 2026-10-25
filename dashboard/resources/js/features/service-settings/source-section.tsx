import { useForm } from '@inertiajs/react';
import { FolderTree, GitBranch, Package } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Logo } from '@/components/logo';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { providerLabel, providerLogo } from '@/features/git/providers';
import { ServiceGlyph } from '@/features/canvas/service-glyph';
import { SettingsCard, SettingsRow } from '@/features/settings/settings-card';
import { cn } from '@/lib/utils';
import services from '@/routes/projects/services';
import { CopyButton } from './general-section';
import { OverrideRow } from './override-row';
import { SaveRow, SettingsPage } from './section';
import type { SettingsContext } from './settings-context';

/** The thing the service runs, as an object: logo, name in mono, chips. */
function SourceHero({
    media,
    eyebrow,
    title,
    chips,
    copy,
    action,
}: {
    media: ReactNode;
    /** Where it comes from, above the name ("GitLab"). */
    eyebrow?: string;
    title: string;
    chips: { icon?: ReactNode; label: string; mono?: boolean }[];
    copy?: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-center gap-4 rounded-xl border bg-card p-5 shadow-card">
            {media}
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                {eyebrow && (
                    <span className="-mb-1.5 type-small text-muted-foreground">
                        {eyebrow}
                    </span>
                )}
                <div className="flex min-w-0 items-center gap-1">
                    <span className="truncate type-mono text-[15px] font-medium">
                        {title}
                    </span>
                    {copy && <CopyButton value={copy} label={copy} />}
                </div>
                <div className="flex flex-wrap gap-1.5">
                    {chips.map((chip) => (
                        <span
                            key={chip.label}
                            className={cn(
                                'inline-flex h-6 items-center gap-1.5 rounded-md border px-2 type-small text-muted-foreground [&_svg]:size-3.5',
                                chip.mono && 'type-mono-small',
                            )}
                        >
                            {chip.icon}
                            {chip.label}
                        </span>
                    ))}
                </div>
            </div>
            {action}
        </div>
    );
}

/**
 * Another image for an image service: project-wide like the name, staged
 * until each environment deploys (docs/04 §1). Tags resolve to a digest
 * when the deploy starts.
 */
function ChangeImageDialog({
    context,
    open,
    onOpenChange,
}: {
    context: SettingsContext;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const current = context.settings.source.image ?? '';
    const form = useForm({ image: current });
    const image = form.data.image.trim();

    useEffect(() => {
        if (open) {
            form.setDefaults({ image: current });
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ image: data.image.trim() }));
        form.patch(services.image(context.route).url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="gap-6 sm:max-w-lg">
                <form onSubmit={submit} className="flex flex-col gap-6">
                    <div className="flex flex-col gap-1.5">
                        <DialogTitle>Change image</DialogTitle>
                        <DialogDescription>
                            Every environment of this project runs the new image
                            after its next deploy. Until then, the current one
                            keeps running.
                        </DialogDescription>
                    </div>
                    <div className="flex flex-col gap-2">
                        <label htmlFor="service-image" className="type-label">
                            Image
                        </label>
                        <Input
                            id="service-image"
                            autoFocus
                            value={form.data.image}
                            onChange={(event) =>
                                form.setData('image', event.target.value)
                            }
                            placeholder="ghcr.io/org/app:1.2"
                            autoComplete="off"
                            spellCheck={false}
                            className="h-11 font-mono text-[15px]"
                        />
                        <InputError message={form.errors.image} />
                        <p className="type-small text-muted-foreground">
                            A tag or a <code>@sha256:</code> digest from Docker
                            Hub or GitHub Container Registry. Tags are pinned to
                            a digest when the deploy starts.
                        </p>
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                image === '' ||
                                image === current
                            }
                        >
                            Save for next deploy
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Source: what the service runs. A repository (branch per environment,
 * root directory), an image (resolved to a digest on every deploy) or a
 * database engine.
 */
export function SourceSection({ context }: { context: SettingsContext }) {
    const { settings, canUpdate, node } = context;
    const source = settings.source;
    const [changingImage, setChangingImage] = useState(false);
    const form = useForm<{ branchOverride: string | null }>({
        branchOverride: source.branchOverride,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            branchOverride:
                data.branchOverride === null ||
                data.branchOverride.trim() === ''
                    ? null
                    : data.branchOverride.trim(),
        }));
        form.patch(services.settings(context.route).url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => form.setDefaults(),
        });
    };

    if (source.type === 'image') {
        const [name, tag] = (source.image ?? '').split(/:(?=[^/:]+$)/);

        return (
            <SettingsPage
                title="Source"
                description="Runs a published image. Tags resolve to a digest on every deploy."
            >
                <SourceHero
                    media={<ServiceGlyph logo={node.logo} size="lg" />}
                    title={source.image ?? ''}
                    copy={source.image ?? undefined}
                    chips={[
                        { icon: <Package />, label: name || 'image' },
                        ...(tag ? [{ label: tag, mono: true }] : []),
                    ]}
                    action={
                        canUpdate && (
                            <Button
                                variant="outline"
                                data-setting="image"
                                onClick={() => setChangingImage(true)}
                            >
                                Change image
                            </Button>
                        )
                    }
                />
                <SettingsCard>
                    <SettingsRow
                        label="Pulled through your registry mirror"
                        description="Your servers fetch each layer once and share it."
                    />
                    <SettingsRow
                        label="Pinned per deploy"
                        description="Each deploy runs the digest it resolved, so a restart doesn’t pick up a newer image."
                    />
                    <SettingsRow
                        label="Build from a repository instead"
                        description="An image service always runs a published image. To build from your code, add the repository as a new service, then move the domains and variables over."
                    />
                </SettingsCard>
                {canUpdate && (
                    <ChangeImageDialog
                        context={context}
                        open={changingImage}
                        onOpenChange={setChangingImage}
                    />
                )}
            </SettingsPage>
        );
    }

    if (source.type === 'database') {
        return (
            <SettingsPage
                title="Source"
                description="A managed database engine. launchroom keeps its data on a volume."
            >
                <SourceHero
                    media={<ServiceGlyph logo={node.logo} size="lg" />}
                    title={node.subtitle}
                    chips={[{ label: `Version ${source.version ?? 'latest'}` }]}
                />
                {source.versions.length > 0 && (
                    <SettingsCard setting="versions" title="Versions">
                        <div className="flex flex-wrap gap-2 px-5 py-4">
                            {source.versions.map((version) => {
                                const current = version === source.version;

                                return (
                                    <span
                                        key={version}
                                        className={cn(
                                            'inline-flex h-8 items-center gap-1.5 rounded-lg border px-3 type-mono',
                                            current
                                                ? 'border-foreground bg-card font-medium'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {version}
                                        {current && (
                                            <span className="font-sans type-small text-muted-foreground">
                                                running
                                            </span>
                                        )}
                                    </span>
                                );
                            })}
                        </div>
                    </SettingsCard>
                )}
            </SettingsPage>
        );
    }

    return (
        <SettingsPage
            title="Source"
            description={
                source.parentName
                    ? `Runs the image built for ${source.parentName}, from the same repository and commit, with its own command.`
                    : 'Pushes to the branch deploy this service in this environment.'
            }
        >
            <SourceHero
                media={
                    <Logo
                        kind={providerLogo(source.provider ?? 'github')}
                        size={44}
                        name={providerLabel(source.provider ?? 'github')}
                        decorative
                    />
                }
                eyebrow={providerLabel(source.provider ?? 'github')}
                title={source.repository ?? 'Repository'}
                chips={[
                    ...(source.branch
                        ? [
                              {
                                  icon: <GitBranch />,
                                  label: source.branch,
                                  mono: true,
                              },
                          ]
                        : []),
                    {
                        icon: <FolderTree />,
                        label: source.rootDirectory || '/',
                        mono: true,
                    },
                ]}
            />
            {!source.parentName && (
                <form onSubmit={submit}>
                    <SettingsCard title="Branch">
                        <OverrideRow
                            setting="branch"
                            label="Deploys from"
                            source="From the environment:"
                            detected={source.environmentBranch}
                            detectedFallback="No branch deploys this environment"
                            value={form.data.branchOverride}
                            disabled={!canUpdate}
                            onChange={(value) =>
                                form.setData('branchOverride', value)
                            }
                            error={form.errors.branchOverride}
                            helper="Only this service follows this branch here; the others keep the environment's."
                        />
                        <SettingsRow
                            setting="root-directory"
                            label="Root directory"
                            description="Where the app lives in the repository. Set when the service was added."
                        >
                            <code className="type-mono text-muted-foreground">
                                {source.rootDirectory || '/'}
                            </code>
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
            )}
        </SettingsPage>
    );
}
