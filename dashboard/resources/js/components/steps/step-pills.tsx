import { cn } from '@/lib/utils';
import { StepIcon, STEP_STATUS_LABEL } from './step-icon';
import type { StepStatus } from './step-icon';

/**
 * Compact horizontal steps for cards and canvas nodes (design-system §5.4):
 * "✓ Build — ✓ Migrate — ◌ Health — Route". Hairline connectors; the
 * running step's name is emphasised.
 */
export function StepPills({
    steps,
    className,
}: {
    steps: { id: string; name: string; status: StepStatus }[];
    className?: string;
}) {
    return (
        <ol
            className={cn(
                'flex flex-wrap items-center gap-x-2 gap-y-1',
                className,
            )}
            aria-label="Deployment steps"
        >
            {steps.map((step, index) => (
                <li key={step.id} className="flex items-center gap-2">
                    {index > 0 && (
                        <span
                            aria-hidden="true"
                            className="h-px w-3 bg-border"
                        />
                    )}
                    <span
                        className={cn(
                            'inline-flex items-center gap-1.5 type-small',
                            step.status === 'running'
                                ? 'font-medium text-foreground'
                                : 'text-muted-foreground',
                        )}
                    >
                        <StepIcon status={step.status} size={14} />
                        {step.name}
                        <span className="sr-only">
                            ({STEP_STATUS_LABEL[step.status]})
                        </span>
                    </span>
                </li>
            ))}
        </ol>
    );
}
