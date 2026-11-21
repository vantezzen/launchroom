import { router } from '@inertiajs/react';
import {
    restartService,
    startService,
} from '@/features/service-lifecycle/actions';
import {
    optimisticQueued,
    rollbackToast,
} from '@/features/service-lifecycle/optimistic';
import { redeploy } from '@/routes/deployments';

/**
 * A status's remedy (App\Enums\ServiceRemedy, D108) as the panel runs
 * it, with the same optimistic status and toasts
 * (features/service-lifecycle/actions.ts): the attention popover and the
 * current deployment card offer it.
 */
export function runRemedy(
    remedy: App.Enums.ServiceRemedy,
    target: {
        project: string;
        environment: string;
        service: string;
        name: string;
        deployment: string | null;
    },
): void {
    const ref = {
        project: target.project,
        environment: target.environment,
        service: target.service,
    };

    switch (remedy) {
        case 'start':
            startService(ref, target.name);
            break;
        case 'start_containers':
            restartService(ref, target.name, { label: 'Starting' });
            break;
        case 'restart':
            restartService(ref, target.name);
            break;
        case 'retry_deploy':
            if (target.deployment !== null) {
                router.post(
                    redeploy({
                        project: target.project,
                        environment: target.environment,
                        deployment: target.deployment,
                    }).url,
                    {},
                    {
                        preserveScroll: true,
                        optimistic: optimisticQueued(target.service),
                        ...rollbackToast(`Couldn’t redeploy ${target.name}.`),
                    },
                );
            }
            break;
    }
}
