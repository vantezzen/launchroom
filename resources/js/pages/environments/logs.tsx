import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import EnvironmentLayout from '@/layouts/environment-layout';
import { usePoll } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';

export default function LogsShow({ logs, error }: { logs: string; error?: string }) {
    usePoll(1000);

    return (
        <EnvironmentLayout title="Logs">
            {error && (
                <Alert variant="destructive" className="mb-4">
                    <TriangleAlert className="mr-2 h-4 w-4" />
                    <AlertTitle>You have no active deployments</AlertTitle>
                    <AlertDescription>
                        You can trigger a deployment from the overview page. Please make sure you have a deployment configured in order to view its
                        logs.
                    </AlertDescription>
                </Alert>
            )}

            <div className="bg-background w-full rounded-lg border border-gray-200 p-4">
                <pre
                    className="h-full max-h-[80vh] max-w-5xl overflow-auto text-sm"
                    ref={(el) => {
                        if (el) {
                            el.scrollTop = el.scrollHeight;
                        }
                    }}
                >
                    {logs}
                </pre>

                <div className="text-muted-foreground mt-2 text-xs">Last updated: {new Date().toLocaleTimeString()}</div>
            </div>
        </EnvironmentLayout>
    );
}
