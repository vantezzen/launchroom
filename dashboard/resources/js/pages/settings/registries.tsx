import { Form, Head } from '@inertiajs/react';
import { LockKeyhole } from 'lucide-react';
import { useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { EmptyState } from '@/components/patterns/empty-state';
import { StatusPill } from '@/components/status';
import type { StatusState } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
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
import {
    SettingsBody,
    SettingsCard,
    SettingsRow,
} from '@/components/settings/settings-card';
import SettingsLayout from '@/features/settings/settings-layout';
import { check, destroy, store, update } from '@/routes/registry-credentials';

type Registry = App.Data.Registry.RegistryCredentialData;

type Props = {
    registries: App.Data.Registry.RegistryCredentialsPageData;
};

const statuses: Record<
    App.Enums.Registry.RegistryCredentialStatus,
    { state: StatusState; label: string }
> = {
    unverified: { state: 'idle', label: 'Not checked' },
    ok: { state: 'active', label: 'Signed in' },
    failed: { state: 'failed', label: 'Sign-in failed' },
};

/**
 * Settings → Private registries (D91, docs/05 §6): the team's sign-in
 * details for registries that hold private images. Deploying such an image
 * copies it into launchroom's own registry first, so servers never get these
 * details. The password or token is never sent back to the browser.
 */
export default function RegistriesSettings({ registries }: Props) {
    const { credentials, canManage } = registries;
    const [editing, setEditing] = useState<Registry | 'new' | null>(null);

    return (
        <>
            <Head title="Private registries" />

            <SettingsCard
                title="Registries"
                description="When you deploy an image from one of these registries, launchroom signs in, copies the image into its own registry and your servers run that copy. The sign-in details stay in launchroom and never reach a server."
                actions={
                    canManage &&
                    credentials.length > 0 && (
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => setEditing('new')}
                        >
                            Add registry
                        </Button>
                    )
                }
            >
                {credentials.length === 0 ? (
                    <SettingsBody>
                        <EmptyState
                            icon={LockKeyhole}
                            title="No private registries yet"
                            variant="plain"
                            action={
                                canManage && (
                                    <Button onClick={() => setEditing('new')}>
                                        Add registry
                                    </Button>
                                )
                            }
                        >
                            Add one to deploy private images from Docker Hub,
                            GitHub, GitLab or any other registry.
                        </EmptyState>
                    </SettingsBody>
                ) : (
                    credentials.map((registry) => (
                        <RegistryRow
                            key={registry.id}
                            registry={registry}
                            canManage={canManage}
                            onEdit={() => setEditing(registry)}
                        />
                    ))
                )}
            </SettingsCard>

            <RegistryDialog
                registry={editing === 'new' ? null : editing}
                open={editing !== null}
                onClose={() => setEditing(null)}
            />
        </>
    );
}

function RegistryRow({
    registry,
    canManage,
    onEdit,
}: {
    registry: Registry;
    canManage: boolean;
    onEdit: () => void;
}) {
    const status = statuses[registry.status];

    return (
        <SettingsRow
            label={<span className="type-mono">{registry.host}</span>}
            description={
                <span className="flex flex-col gap-1">
                    <span>
                        Signed in as {registry.username}
                        {registry.lastCheckedAt && (
                            <>
                                , checked{' '}
                                <TimeAgo value={registry.lastCheckedAt} />
                            </>
                        )}
                    </span>
                    {registry.status === 'failed' && registry.lastError && (
                        <span className="text-status-error">
                            {registry.lastError}
                        </span>
                    )}
                </span>
            }
            align="start"
        >
            <div className="flex flex-wrap items-center gap-2">
                <StatusPill state={status.state} label={status.label} />
                {canManage && (
                    <>
                        <Form
                            {...check.form(registry.id)}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="ghost"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Check
                                </Button>
                            )}
                        </Form>
                        <Button size="sm" variant="ghost" onClick={onEdit}>
                            Edit
                        </Button>
                        <ConfirmAction
                            trigger="Remove"
                            title={`Remove ${registry.host}?`}
                            description="Images launchroom already copied keep running. New deploys of images from this registry stop working until you add it again."
                            confirmLabel="Remove registry"
                            form={destroy.form(registry.id)}
                            destructive
                        />
                    </>
                )}
            </div>
        </SettingsRow>
    );
}

function RegistryDialog({
    registry,
    open,
    onClose,
}: {
    registry: Registry | null;
    open: boolean;
    onClose: () => void;
}) {
    const editing = registry !== null;
    const action = editing ? update.form(registry.id) : store.form();

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    onClose();
                }
            }}
        >
            <DialogContent>
                <DialogTitle>
                    {editing ? `Edit ${registry.host}` : 'Add a registry'}
                </DialogTitle>
                <DialogDescription>
                    Use an access token that can only read images where the
                    registry offers one.
                </DialogDescription>
                <Form
                    {...action}
                    key={registry?.id ?? 'new'}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="grid gap-4"
                    disableWhileProcessing
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="registry-host">Registry</Label>
                                <Input
                                    id="registry-host"
                                    name="host"
                                    className="font-mono"
                                    placeholder="ghcr.io"
                                    defaultValue={registry?.host ?? ''}
                                    readOnly={editing}
                                    required
                                />
                                <p className="type-small text-muted-foreground">
                                    As it appears in image names, for example
                                    docker.io, ghcr.io or registry.gitlab.com.
                                </p>
                                <InputError message={errors.host} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="registry-username">
                                    Username
                                </Label>
                                <Input
                                    id="registry-username"
                                    name="username"
                                    autoComplete="off"
                                    defaultValue={registry?.username ?? ''}
                                    required
                                />
                                <InputError message={errors.username} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="registry-password">
                                    Password or access token
                                </Label>
                                <PasswordInput
                                    id="registry-password"
                                    name="password"
                                    autoComplete="new-password"
                                    required={!editing}
                                    placeholder={editing ? 'Unchanged' : ''}
                                />
                                <InputError message={errors.password} />
                            </div>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="ghost" type="button">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {editing ? 'Save' : 'Add registry'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

RegistriesSettings.layout = SettingsLayout;
