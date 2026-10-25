import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { promoteDeployment } from '@/routes/environments';
import {
    cancel,
    redeploy,
    rollback,
    show,
    service as serviceDeployments,
} from '@/routes/deployments';
import { redeploy as redeployService } from '@/routes/deployments/service';
import { shortSha } from './format';

type Summary = App.Data.Deployments.DeploymentSummaryData;

export function deploymentUrl(deployment: Summary): string {
    return show({
        project: deployment.projectId,
        environment: deployment.environmentId,
        deployment: deployment.id,
    }).url;
}

export function deploymentArgs(deployment: Summary) {
    return {
        project: deployment.projectId,
        environment: deployment.environmentId,
        deployment: deployment.id,
    };
}

export function serviceDeploymentsUrl(
    project: string,
    environment: string,
    service: string,
): string {
    return serviceDeployments({ project, environment, service }).url;
}

export type PendingAction =
    | { kind: 'rollback'; deployment: Summary }
    | { kind: 'redeploy'; deployment: Summary }
    | {
          kind: 'redeploy-service';
          project: string;
          environment: string;
          service: string;
          name: string;
      }
    | { kind: 'cancel'; deployment: Summary }
    | { kind: 'promote'; deployment: Summary };

function describe(action: PendingAction): {
    title: string;
    description: ReactNode;
    confirm: string;
    destructive?: boolean;
} {
    switch (action.kind) {
        case 'rollback':
            return {
                title: `Roll back ${action.deployment.serviceName} to ${shortSha(action.deployment.commitSha) ?? action.deployment.id}?`,
                description: (
                    <>
                        Traffic moves back to this deployment’s image and
                        configuration right away, without a rebuild. Database
                        migrations are not reverted. In production, new pushes
                        stop going live until you resume auto-deploy.
                    </>
                ),
                confirm: 'Roll back',
            };
        case 'redeploy':
            return {
                title: `Redeploy ${action.deployment.serviceName}?`,
                description:
                    'A new deployment of the same commit with the last deployed configuration. Staged changes stay staged.',
                confirm: 'Redeploy',
            };
        case 'redeploy-service':
            return {
                title: `Redeploy ${action.name}?`,
                description:
                    'A new deployment of the current commit with the last deployed configuration. Staged changes stay staged.',
                confirm: 'Redeploy',
            };
        case 'cancel':
            return {
                title: 'Cancel this deployment?',
                description:
                    action.deployment.status === 'ready'
                        ? 'The staged version stops and the current version keeps serving.'
                        : 'The build stops and the current version keeps serving.',
                confirm: 'Cancel deployment',
                destructive: true,
            };
        case 'promote':
            return {
                title: `Promote ${action.deployment.serviceName}?`,
                description:
                    'Traffic switches to the staged version, which is already running and healthy. Nothing is rebuilt or restarted, and the current version drains.',
                confirm: 'Promote',
            };
    }
}

function submit(action: PendingAction, onFinish: () => void) {
    const options = { preserveScroll: true, onFinish };

    if (action.kind === 'promote') {
        router.post(
            promoteDeployment(deploymentArgs(action.deployment)).url,
            {},
            options,
        );

        return;
    }

    switch (action.kind) {
        case 'rollback':
            router.post(
                rollback(deploymentArgs(action.deployment)).url,
                {},
                options,
            );
            break;
        case 'redeploy':
            router.post(
                redeploy(deploymentArgs(action.deployment)).url,
                {},
                options,
            );
            break;
        case 'redeploy-service':
            router.post(
                redeployService({
                    project: action.project,
                    environment: action.environment,
                    service: action.service,
                }).url,
                {},
                options,
            );
            break;
        case 'cancel':
            router.post(
                cancel(deploymentArgs(action.deployment)).url,
                {},
                options,
            );
            break;
    }
}

/**
 * Confirmation for redeploy, rollback and cancel. Each action is an Inertia
 * visit; redeploy and rollback land on the new deployment's page.
 */
export function DeploymentActionDialog({
    action,
    onClose,
}: {
    action: PendingAction | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);
    const text = action ? describe(action) : null;

    return (
        <Dialog
            open={action !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                {text && action && (
                    <>
                        <DialogTitle>{text.title}</DialogTitle>
                        <DialogDescription>
                            {text.description}
                        </DialogDescription>
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary" type="button">
                                    Keep as is
                                </Button>
                            </DialogClose>
                            <Button
                                type="button"
                                variant={
                                    text.destructive ? 'destructive' : 'default'
                                }
                                disabled={processing}
                                onClick={() => {
                                    setProcessing(true);
                                    submit(action, () => {
                                        setProcessing(false);
                                        onClose();
                                    });
                                }}
                            >
                                {text.confirm}
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
