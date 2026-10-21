import { Plus, TriangleAlert, X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';

type Plan = App.Data.Canvas.DetectedPlanData;

const builderLabels: Record<string, string> = {
    explicit: 'as configured',
    dockerfile_detected: 'from your Dockerfile',
    preset: 'with a launchroom preset',
    railpack: 'with Railpack',
};

/**
 * "Next.js · pnpm 9.12 · Node 22.11 (from .nvmrc) · port 3000".
 */
export function planHeadline(plan: Plan): string[] {
    return [
        plan.framework &&
            [plan.framework, plan.frameworkVersion].filter(Boolean).join(' '),
        plan.packageManager,
        plan.runtime &&
            `${plan.runtime}${plan.runtimeSource ? ` (from ${plan.runtimeSource})` : ''}`,
        plan.port !== null && `port ${plan.port}`,
    ].filter((part): part is string => Boolean(part));
}

/**
 * "Next.js 16.4 · standalone · ISR routes: 2 · middleware" from the
 * launchroom Next.js adapter's build report.
 */
export function nextHeadline(
    next: App.Data.Protocol.V1.NextBuildInfo,
): string[] {
    const [major, minor] = next.nextVersion.replace(/^v/, '').split('.');

    return [
        `Next.js ${major}.${minor}`,
        next.serverEntry && 'standalone',
        `ISR routes: ${next.routes.isr}`,
        next.middleware &&
            (next.middlewareRuntime === 'edge'
                ? 'middleware (edge)'
                : 'middleware'),
        next.cacheHandler && 'shared cache handler',
    ].filter((part): part is string => Boolean(part));
}

function NextDetails({ next }: { next: App.Data.Protocol.V1.NextBuildInfo }) {
    const routes = [
        ['App pages', next.routes.appPages],
        ['Route handlers', next.routes.appRoutes],
        ['Pages', next.routes.pages],
        ['API routes', next.routes.pagesApi],
        ['Prerendered', next.routes.prerendered],
        ['Static files', next.routes.staticFiles],
    ].filter((entry): entry is [string, number] => (entry[1] as number) > 0);

    return (
        <div className="flex flex-col gap-1" aria-label="Next.js build">
            <span className="type-label text-muted-foreground">
                Next.js build
            </span>
            <p className="type-body">{nextHeadline(next).join(' · ')}</p>
            {routes.length > 0 && (
                <p className="type-small text-muted-foreground">
                    {routes
                        .map(([label, count]) => `${label}: ${count}`)
                        .join(' · ')}
                </p>
            )}
            {next.isrRoutes && next.isrRoutes.length > 0 && (
                <p className="type-small text-muted-foreground">
                    Revalidated:{' '}
                    <span className="font-mono text-foreground">
                        {next.isrRoutes.slice(0, 8).join(', ')}
                    </span>
                    {next.isrRoutes.length > 8 &&
                        ` and ${next.isrRoutes.length - 8} more`}
                </p>
            )}
            <p className="type-small text-muted-foreground">
                Image optimization{' '}
                {next.imageOptimization
                    ? next.sharp
                        ? 'on (sharp)'
                        : 'on (without sharp)'
                    : 'off'}{' '}
                · launchroom adapter {next.adapterVersion}
            </p>
        </div>
    );
}

/**
 * Railpack's plan as JSON, read-only (docs/04 §4 "View plan JSON").
 */
export function PlanJsonDialog({
    plan,
    open,
    onOpenChange,
}: {
    plan: Record<string, unknown>;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const json = JSON.stringify(plan, null, 2);
    const [copied, copy] = useClipboard();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-3xl">
                <DialogTitle>Build plan</DialogTitle>
                <DialogDescription>
                    The plan Railpack generated for this commit, after presets
                    and overrides. Read-only.
                </DialogDescription>
                <pre className="max-h-[60vh] overflow-auto rounded-md border bg-muted p-3 type-mono-small">
                    {json}
                </pre>
                <div className="flex justify-end">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => void copy(json)}
                    >
                        {copied === json ? 'Copied' : 'Copy JSON'}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function DecisionChip({
    decision,
    removed,
    onToggle,
}: {
    decision: App.Data.Protocol.V1.PresetDecision;
    removed: boolean;
    onToggle?: () => void;
}) {
    return (
        <li
            className={cn(
                'inline-flex h-6 max-w-full items-center gap-1 rounded-[6px] border pr-1 pl-2 type-small',
                removed && 'border-dashed text-muted-foreground',
                !onToggle && 'pr-2',
            )}
            title={decision.detail ?? undefined}
        >
            <span className={cn('truncate', removed && 'line-through')}>
                {decision.title}
            </span>
            {removed && <span className="sr-only">(removed)</span>}
            {onToggle && (
                <button
                    type="button"
                    onClick={onToggle}
                    className="flex size-4 shrink-0 items-center justify-center rounded-[4px] text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    aria-label={
                        removed
                            ? `Restore: ${decision.title}`
                            : `Remove: ${decision.title}`
                    }
                >
                    {removed ? (
                        <Plus aria-hidden="true" className="size-3" />
                    ) : (
                        <X aria-hidden="true" className="size-3" />
                    )}
                </button>
            )}
        </li>
    );
}

/**
 * "We detected…" (docs/04 §4): framework, toolchain, commands, port,
 * preset decisions (removable chips when `onToggleDecision` is given), the
 * variables the code references and warnings.
 */
export function DetectedPlan({
    plan,
    emptyMessage,
    disabledDecisions = [],
    onToggleDecision,
    showPlanJson = true,
}: {
    plan: Plan | null;
    emptyMessage?: string;
    disabledDecisions?: string[];
    onToggleDecision?: (id: string) => void;
    showPlanJson?: boolean;
}) {
    const [jsonOpen, setJsonOpen] = useState(false);

    if (!plan) {
        return (
            <p className="max-w-[65ch] type-body text-muted-foreground">
                {emptyMessage ??
                    'launchroom detects the framework, commands and port when the first build runs.'}
            </p>
        );
    }

    const headline = planHeadline(plan);
    const commands = [
        ['Install', plan.installCommand],
        ['Build', plan.buildCommand],
        ['Start', plan.startCommand],
        ['Release', plan.releaseCommand],
    ].filter((entry): entry is [string, string] => entry[1] !== null);
    const decisions = plan.presets.flatMap((preset) =>
        preset.decisions.map((decision) => ({ preset, decision })),
    );
    const warnings = [
        ...plan.missingVariables.map(
            (key) => `${key} is referenced in code but not defined.`,
        ),
        ...plan.warnings,
    ];

    return (
        <div className="flex flex-col gap-4 rounded-lg border p-4">
            <div className="flex flex-col gap-1">
                <p className="type-body">
                    <span className="text-muted-foreground">We detected </span>
                    <span className="font-medium">
                        {headline.length > 0
                            ? headline.join(' · ')
                            : 'a buildable app'}
                    </span>
                </p>
                {plan.builder && (
                    <p className="type-small text-muted-foreground">
                        Built{' '}
                        {plan.builder === 'dockerfile'
                            ? 'from a Dockerfile'
                            : (builderLabels[plan.builderReason ?? ''] ??
                              'with Railpack')}
                        {plan.railpackVersion &&
                            plan.builder === 'railpack' &&
                            ` · Railpack ${plan.railpackVersion}`}
                    </p>
                )}
            </div>

            {plan.next && <NextDetails next={plan.next} />}

            {commands.length > 0 && (
                <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-[auto_1fr]">
                    {commands.map(([label, command]) => (
                        <div key={label} className="contents">
                            <dt className="type-label text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="min-w-0 type-mono break-all">
                                {command}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}

            {decisions.length > 0 && (
                <div className="flex flex-col gap-2">
                    <span className="type-label text-muted-foreground">
                        Preset decisions
                        {plan.presets.length === 1 &&
                            ` (${plan.presets[0].name})`}
                    </span>
                    <ul
                        className="flex flex-wrap gap-1.5"
                        aria-label="Preset decisions"
                    >
                        {decisions.map(({ decision }) => (
                            <DecisionChip
                                key={decision.id}
                                decision={decision}
                                removed={disabledDecisions.includes(
                                    decision.id,
                                )}
                                onToggle={
                                    onToggleDecision
                                        ? () => onToggleDecision(decision.id)
                                        : undefined
                                }
                            />
                        ))}
                    </ul>
                    {onToggleDecision && (
                        <p className="type-small text-muted-foreground">
                            Remove a decision to build without it. Saved with
                            the build settings; applies to the next build.
                        </p>
                    )}
                </div>
            )}

            {(plan.buildVariables.length > 0 ||
                plan.runtimeVariableCount > 0 ||
                plan.envReferences.length > 0) && (
                <div className="flex flex-col gap-1 type-small text-muted-foreground">
                    {plan.buildVariables.length > 0 && (
                        <p>
                            Build-time variables:{' '}
                            <span className="font-mono text-foreground">
                                {plan.buildVariables.join(', ')}
                            </span>
                        </p>
                    )}
                    {plan.envReferences.length > 0 && (
                        <p>
                            Referenced in code:{' '}
                            <span className="font-mono text-foreground">
                                {plan.envReferences.slice(0, 12).join(', ')}
                            </span>
                            {plan.envReferences.length > 12 &&
                                ` and ${plan.envReferences.length - 12} more`}
                        </p>
                    )}
                    {plan.runtimeVariableCount > 0 && (
                        <p>
                            Set by the preset at runtime:{' '}
                            {plan.runtimeVariableCount}{' '}
                            {plan.runtimeVariableCount === 1
                                ? 'variable'
                                : 'variables'}
                        </p>
                    )}
                </div>
            )}

            {warnings.length > 0 && (
                <ul className="flex flex-col gap-1.5">
                    {warnings.map((warning) => (
                        <li key={warning} className="flex gap-2 type-small">
                            <TriangleAlert
                                aria-hidden="true"
                                className="mt-0.5 size-3.5 shrink-0 text-status-progress"
                            />
                            <span>
                                <span className="sr-only">Warning: </span>
                                {warning}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {showPlanJson && plan.planJson && (
                <div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="-ml-2"
                        onClick={() => setJsonOpen(true)}
                    >
                        View plan JSON
                    </Button>
                    <PlanJsonDialog
                        plan={plan.planJson}
                        open={jsonOpen}
                        onOpenChange={setJsonOpen}
                    />
                </div>
            )}
        </div>
    );
}
