import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import useBaseUrl from '@/hooks/use-base-url';
import useReloadOnChannel from '@/hooks/use-reload-on-channel';
import EnvironmentLayout from '@/layouts/environment-layout';
import { Deployment, SharedData } from '@/types';
import { timeAgo } from '@/utils/time';
import { usePage } from '@inertiajs/react';
import { Clock, ExternalLink } from 'lucide-react';
import { BuildLogs } from './BuildLog';

const calculateBuildTime = (deployment: Deployment) => {
    if (!deployment.started_at) return 'N/A';

    const start = new Date(deployment.started_at).getTime();
    const end = new Date(deployment.finished_at || new Date()).getTime();
    const diffInSeconds = Math.floor((end - start) / 1000);

    return `${diffInSeconds}s`;
};
const getStatusDisplay = (status: Deployment['status']) => {
    switch (status) {
        case 'succeeded':
            return 'Ready';
        case 'failed':
            return 'Failed';
        case 'running':
            return 'Building';
        case 'pending':
            return 'Pending';
        default:
            return status;
    }
};
const getStatusColor = (status: Deployment['status']) => {
    switch (status) {
        case 'succeeded':
            return 'text-green-500';
        case 'failed':
            return 'text-red-500';
        case 'running':
            return 'text-blue-500';
        case 'pending':
            return 'text-amber-500';
        default:
            return 'text-gray-500';
    }
};

export default function DeploymentShow({ deployment }: { deployment: Deployment }) {
    const baseUrl = useBaseUrl();
    const {
        props: { currentEnvironment },
    } = usePage<SharedData>();

    useReloadOnChannel(`App.Models.Deployment.${deployment.id}`);

    return (
        <EnvironmentLayout
            title={'Deployment Details'}
            actions={
                <>
                    <a href={`http://${currentEnvironment?.domains[0]}`} target="_blank" rel="noreferrer">
                        <Button>
                            <ExternalLink className="mr-2 h-4 w-4" />
                            Visit
                        </Button>
                    </a>
                </>
            }
        >
            <div className="mb-8 grid gap-6">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div className="space-y-1">
                        <div className="text-muted-foreground text-sm">Created</div>
                        <div className="flex items-center gap-2">
                            <div>{timeAgo(deployment.created_at)}</div>
                            <div className="text-muted-foreground text-sm">{new Date(deployment.created_at).toLocaleDateString()}</div>
                        </div>
                    </div>

                    <div className="space-y-1">
                        <div className="text-muted-foreground text-sm">Status</div>
                        <div className="flex items-center gap-2">
                            <div className={`font-medium ${getStatusColor(deployment.status)}`}>
                                {deployment.status.charAt(0).toUpperCase() + deployment.status.slice(1)}
                            </div>
                        </div>
                    </div>

                    <div className="space-y-1">
                        <div className="text-muted-foreground text-sm">Duration</div>
                        <div className="flex items-center gap-2">
                            <Clock className="h-4 w-4" />
                            <span>{calculateBuildTime(deployment)}</span>
                        </div>
                    </div>

                    <div className="space-y-1">
                        <div className="text-muted-foreground text-sm">Environment</div>
                        <div className="flex items-center gap-2">
                            <span>{currentEnvironment.name}</span>
                            {deployment.is_latest && (
                                <Badge variant="outline" className="bg-background border-blue-500 text-blue-500">
                                    Current
                                </Badge>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <div className="overflow-hidden rounded-lg border">
                <div className="bg-muted/40 border-b p-4">
                    <div className="font-medium">Build Logs</div>
                </div>
                <BuildLogs output={deployment.output} />
            </div>
        </EnvironmentLayout>
    );
}
