import { Form } from '@inertiajs/react';
import { Pause } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { resume } from '@/routes/environments';

/**
 * After a rollback of production, pushes don't go live until resumed
 * (docs/04 §8): "Production is rolled back. New pushes won't go live."
 */
export function AutoDeployPausedBanner({
    project,
    environment,
    canResume,
}: {
    project: string;
    environment: App.Data.Canvas.EnvironmentData;
    canResume: boolean;
}) {
    return (
        <div
            role="status"
            className="flex flex-wrap items-center gap-x-3 gap-y-1 border-t px-3 py-2 md:px-4"
        >
            <Pause
                aria-hidden="true"
                className="size-4 shrink-0 text-status-progress"
            />
            <p className="type-body">
                <span className="font-medium">
                    {environment.name} is rolled back.
                </span>{' '}
                <span className="text-muted-foreground">
                    New pushes won’t go live until auto-deploy is resumed.
                </span>
            </p>
            {canResume && (
                <Form
                    {...resume.form([project, environment.id])}
                    options={{ preserveScroll: true }}
                    className="ml-auto"
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            disabled={processing}
                        >
                            Resume auto-deploy
                        </Button>
                    )}
                </Form>
            )}
        </div>
    );
}
