import { router } from '@inertiajs/react';
import type { VisitOptions } from '@inertiajs/core';
import { toast } from 'sonner';
import { redeploy } from '@/routes/deployments/service';
import lifecycle from '@/routes/services/lifecycle';
import {
    optimisticQueued,
    optimisticService,
    rollbackToast,
} from './optimistic';

export type ServiceRef = {
    project: string;
    environment: string;
    service: string;
};

type Options = {
    /** Include the service's processes (workers, crons); default true. */
    processes?: boolean;
    onSuccess?: () => void;
    onFinish?: () => void;
};

const stay: Partial<VisitOptions> = {
    preserveScroll: true,
    preserveState: true,
};

/**
 * The service lifecycle actions (docs/08, D68), with the same optimistic
 * state, toasts and rollback wherever they are offered: the panel header,
 * the canvas node menu and ⌘K.
 */
export function restartService(
    ref: ServiceRef,
    name: string,
    {
        processes = true,
        onSuccess,
        onFinish,
        label = 'Restarting',
    }: Options & {
        /** "Starting" when nothing runs (a remedy for exited containers, D108). */
        label?: 'Restarting' | 'Starting';
    } = {},
): void {
    router.post(
        lifecycle.restart(ref).url,
        { processes },
        {
            ...stay,
            optimistic: optimisticService(
                ref.service,
                'deploying',
                { deploying: true },
                label,
            ),
            onSuccess: () => {
                toast.success(`${label} ${name}`);
                onSuccess?.();
            },
            onFinish,
            ...rollbackToast(`Couldn’t restart ${name}.`),
        },
    );
}

export function stopService(
    ref: ServiceRef,
    name: string,
    { processes = true, onSuccess, onFinish }: Options = {},
): void {
    router.post(
        lifecycle.stop(ref).url,
        { processes },
        {
            ...stay,
            optimistic: optimisticService(ref.service, 'stopped', {
                stopped: true,
                stoppedAt: new Date().toISOString(),
            }),
            onSuccess: () => {
                toast.success(`${name} is stopped`);
                onSuccess?.();
            },
            onFinish,
            ...rollbackToast(`Couldn’t stop ${name}.`),
        },
    );
}

export function startService(
    ref: ServiceRef,
    name: string,
    { processes = true, onSuccess, onFinish }: Options = {},
): void {
    router.post(
        lifecycle.start(ref).url,
        { processes },
        {
            ...stay,
            optimistic: optimisticService(ref.service, 'starting', {
                stopped: false,
                stoppedAt: null,
                stoppedBy: null,
            }),
            onSuccess: () => {
                toast.success(`Starting ${name}`);
                onSuccess?.();
            },
            onFinish,
            ...rollbackToast(`Couldn’t start ${name}.`),
        },
    );
}

/** A new deployment of the running version; lands on its page. */
export function redeployService(
    ref: ServiceRef,
    name: string | null,
    { onFinish }: Pick<Options, 'onFinish'> = {},
): void {
    router.post(
        redeploy(ref).url,
        {},
        {
            optimistic: optimisticQueued(ref.service),
            onFinish,
            ...rollbackToast(
                name === null
                    ? 'Couldn’t redeploy.'
                    : `Couldn’t redeploy ${name}.`,
            ),
        },
    );
}

/** Deploys the service's current configuration; lands on the new deployment. */
export function deployService(
    ref: ServiceRef,
    name: string,
    { onFinish }: Pick<Options, 'onFinish'> = {},
): void {
    router.post(
        lifecycle.deploy(ref).url,
        {},
        {
            optimistic: optimisticQueued(ref.service),
            onFinish,
            ...rollbackToast(`Couldn’t deploy ${name}.`),
        },
    );
}
