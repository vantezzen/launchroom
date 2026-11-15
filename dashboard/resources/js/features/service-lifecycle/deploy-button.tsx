import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { openStagedChanges } from '@/features/staged-changes/staged-changes-sheet';
import { deployService } from './actions';
import { LIFECYCLE_ICON } from './lifecycle';

const DeployIcon = LIFECYCLE_ICON.deploy;

/**
 * Deploy: the latest commit of the tracked branch (or the image's tag
 * again; a database, worker or cron that never ran: its last deployed
 * configuration); with staged changes, "Review & apply", which opens the
 * environment's staged changes (D75).
 * The header's primary, and the action of the Deployments tab's empty
 * state.
 */
export function DeployButton({
    project,
    environment,
    service,
    name,
    staged,
    versioned = true,
    disabled = false,
    variant = 'default',
    className,
}: {
    project: string;
    environment: string;
    service: string;
    name: string;
    staged: boolean;
    versioned?: boolean;
    disabled?: boolean;
    variant?: 'default' | 'outline';
    className?: string;
}) {
    const [busy, setBusy] = useState(false);

    const deploy = () => {
        if (staged) {
            openStagedChanges();

            return;
        }

        setBusy(true);

        deployService({ project, environment, service }, name, {
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    size="sm"
                    variant={variant}
                    onClick={deploy}
                    disabled={disabled || busy}
                    className={className}
                >
                    <DeployIcon aria-hidden="true" />
                    {staged ? 'Review & apply' : 'Deploy'}
                </Button>
            </TooltipTrigger>
            <TooltipContent>
                {staged
                    ? 'Review the staged changes of this environment and apply them'
                    : versioned
                      ? 'Builds and deploys the latest version'
                      : 'Deploys the last deployed configuration again'}
            </TooltipContent>
        </Tooltip>
    );
}
