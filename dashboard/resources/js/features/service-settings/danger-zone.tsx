import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { Button } from '@/components/ui/button';
import { SettingsCard, SettingsRow } from '@/features/settings/settings-card';
import services from '@/routes/projects/services';
import { RemoveServiceDialog } from './remove-service-dialog';
import { SettingsPage } from './section';
import type { SettingsContext } from './settings-context';

/**
 * Danger zone: disable (keeps everything, stops it with the next deploy)
 * and remove from this environment (staged). Rows with outline buttons;
 * the confirmation lives in the popover or dialog (design-system §13.9).
 */
export function DangerZone({ context }: { context: SettingsContext }) {
    const [removing, setRemoving] = useState(false);
    const { node, settings } = context;
    const [project, environment] = context.route;

    if (!context.canUpdate && !context.canDelete) {
        return null;
    }

    const toggle = () =>
        new Promise<void>((resolve) =>
            router.patch(
                services.settings(context.route).url,
                { enabled: !settings.enabled },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onFinish: () => resolve(),
                },
            ),
        );

    return (
        <SettingsPage
            title="Danger zone"
            description="Each of these is staged: nothing changes until you deploy."
        >
            <SettingsCard>
                {context.canUpdate && (
                    <SettingsRow
                        setting="disable"
                        label={
                            settings.enabled
                                ? 'Disable service'
                                : 'Enable service'
                        }
                        description={
                            settings.enabled
                                ? 'Stops it in this environment. Configuration, variables and data stay.'
                                : 'Starts it again with the next deploy.'
                        }
                    >
                        <ConfirmPopover
                            title={
                                settings.enabled
                                    ? `Disable ${node.name}?`
                                    : `Enable ${node.name}?`
                            }
                            consequence={
                                settings.enabled
                                    ? 'It stops with the next deploy and keeps everything.'
                                    : 'It starts with the next deploy.'
                            }
                            confirmLabel={
                                settings.enabled ? 'Disable' : 'Enable'
                            }
                            onConfirm={toggle}
                            align="end"
                        >
                            <Button variant="outline">
                                {settings.enabled ? 'Disable' : 'Enable'}
                            </Button>
                        </ConfirmPopover>
                    </SettingsRow>
                )}
                {context.canDelete && (
                    <SettingsRow
                        setting="remove"
                        label="Remove from this environment"
                        description="Volumes are kept, and other environments keep their copy."
                    >
                        <Button
                            variant="destructive"
                            onClick={() => setRemoving(true)}
                        >
                            Remove service
                        </Button>
                    </SettingsRow>
                )}
            </SettingsCard>
            <RemoveServiceDialog
                project={project}
                environment={environment}
                service={node}
                open={removing}
                onOpenChange={setRemoving}
            />
        </SettingsPage>
    );
}
