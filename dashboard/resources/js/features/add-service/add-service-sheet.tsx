import { router } from '@inertiajs/react';
import { useReactFlow } from '@xyflow/react';
import { ChevronLeft } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Logo } from '@/components/logo';
import { SidePanel } from '@/components/patterns/sheet';
import { Button } from '@/components/ui/button';
import { NODE_HEIGHT, NODE_WIDTH } from '@/features/canvas/auto-layout';
import {
    closeAddService,
    useAddService,
} from '@/features/projects/add-service-store';
import services from '@/routes/projects/services';
import { CatalogPicker } from '@/features/templates/catalog-picker';
import { GitHubForm } from './github-form';
import { DatabaseForm, ImageForm, ProcessForm } from './simple-forms';
import { takePreset } from './preset';
import type { AddChoice as Choice } from './preset';
import type { AddServicePayload } from './types';

const choices: {
    id: Choice;
    logo: string;
    title: string;
    description: string;
}[] = [
    {
        id: 'github',
        logo: 'github',
        title: 'GitHub repository',
        description: 'Detects how to build it and deploys on every push.',
    },
    {
        id: 'catalog',
        logo: 'app',
        title: 'From the catalog',
        description: 'Apps and stacks, ready to run.',
    },
    {
        id: 'database',
        logo: 'postgres',
        title: 'Database',
        description: 'Postgres, MySQL, Redis, Valkey, MongoDB or ClickHouse.',
    },
    {
        id: 'image',
        logo: 'docker',
        title: 'Docker image',
        description: 'Any image from a registry.',
    },
    {
        id: 'worker',
        logo: 'worker',
        title: 'Worker',
        description: 'Processes a queue for one of your apps.',
    },
    {
        id: 'cron',
        logo: 'cron',
        title: 'Cron',
        description: 'Runs a command of one of your apps on a schedule.',
    },
];

/**
 * "+ Add" (docs/08 §1): GitHub repository, image,
 * database, worker or cron. The new service is staged and placed in the
 * middle of the visible canvas.
 */
export function AddServiceSheet({
    project,
    environment,
    engines,
    parents,
    consumers = [],
}: {
    project: string;
    environment: string;
    engines: App.Data.Canvas.DatabaseEngineOptionData[];
    parents: { id: string; name: string }[];
    /** Services a new database can connect to right away. */
    consumers?: { id: string; name: string; logo: string }[];
}) {
    const { open, position } = useAddService();
    const flow = useReactFlow();
    const [choice, setChoice] = useState<Choice | null>(null);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (open) {
            setChoice(takePreset());
            setErrors({});
        }
    }, [open]);

    const placement = () => {
        if (position) {
            return position;
        }

        const pane = document.querySelector('.launchroom-canvas');
        const rect = pane?.getBoundingClientRect();
        const center = rect
            ? flow.screenToFlowPosition({
                  x: rect.left + rect.width / 2,
                  y: rect.top + rect.height / 2,
              })
            : { x: 0, y: 0 };
        // Nudge off existing nodes at the same spot.
        const taken = flow
            .getNodes()
            .some(
                (node) =>
                    Math.abs(node.position.x - (center.x - NODE_WIDTH / 2)) <
                        NODE_WIDTH &&
                    Math.abs(node.position.y - (center.y - NODE_HEIGHT / 2)) <
                        NODE_HEIGHT,
            );

        return {
            x: Math.round(
                center.x - NODE_WIDTH / 2 + (taken ? NODE_WIDTH + 48 : 0),
            ),
            y: Math.round(center.y - NODE_HEIGHT / 2),
        };
    };

    const submit = (payload: AddServicePayload) => {
        setProcessing(true);
        router.post(
            services.store([project, environment]).url,
            { ...payload, position: placement() },
            {
                onSuccess: () => closeAddService(),
                onError: (failed) => setErrors(failed),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const selected = choices.find((item) => item.id === choice);
    const formProps = { submit, processing, errors };

    return (
        <SidePanel
            open={open}
            onOpenChange={(next) => !next && closeAddService()}
            size="md"
            title={
                selected
                    ? selected.id === 'catalog'
                        ? 'Add from the catalog'
                        : `Add ${selected.title.toLowerCase()}`
                    : 'Add to the canvas'
            }
            description="New services start with the next deploy."
            media={
                selected ? (
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        className="-ml-1.5"
                        aria-label="Back to service types"
                        onClick={() => {
                            setChoice(null);
                            setErrors({});
                        }}
                    >
                        <ChevronLeft />
                    </Button>
                ) : undefined
            }
        >
            <div className="flex flex-col gap-4 px-5 py-5">
                {choice === null && (
                    <div
                        role="list"
                        aria-label="What to add"
                        className="grid grid-cols-2 gap-3"
                    >
                        {choices.map((item, index) => (
                            <button
                                key={item.id}
                                role="listitem"
                                type="button"
                                onClick={() => setChoice(item.id)}
                                className={
                                    'flex min-h-32 flex-col items-start gap-3 rounded-xl border bg-card p-4 text-left transition-colors duration-fast hover:border-border-strong focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none' +
                                    (index <= 1 ? ' col-span-2' : '')
                                }
                            >
                                <Logo kind={item.logo} size={32} decorative />
                                <span className="flex flex-col gap-0.5">
                                    <span className="type-label">
                                        {item.title}
                                    </span>
                                    <span className="type-small text-muted-foreground">
                                        {item.description}
                                    </span>
                                </span>
                            </button>
                        ))}
                    </div>
                )}
                {choice === 'github' && (
                    <GitHubForm project={project} {...formProps} />
                )}
                {choice === 'catalog' && (
                    <CatalogPicker
                        project={project}
                        environment={environment}
                        onDone={() => closeAddService()}
                    />
                )}
                {choice === 'image' && <ImageForm {...formProps} />}
                {choice === 'database' && (
                    <DatabaseForm
                        engines={engines}
                        consumers={consumers}
                        {...formProps}
                    />
                )}
                {(choice === 'worker' || choice === 'cron') && (
                    <ProcessForm
                        key={choice}
                        kind={choice}
                        parents={parents}
                        {...formProps}
                    />
                )}
            </div>
        </SidePanel>
    );
}
