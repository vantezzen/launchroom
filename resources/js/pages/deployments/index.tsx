import { Badge } from '@/components/ui/badge';
import EmptyState from '@/components/ui/empty-state';
import useBaseUrl from '@/hooks/use-base-url';
import EnvironmentLayout from '@/layouts/environment-layout';
import { Deployment, SharedData } from '@/types';
import { timeAgo } from '@/utils/time';
import { Link, usePage } from '@inertiajs/react';

const calculateBuildTime = (deployment: Deployment) => {
    if (!deployment.started_at) return 'N/A';

    const start = new Date(deployment.started_at).getTime();
    const end = new Date(deployment.finished_at || new Date()).getTime();
    const diffInSeconds = Math.floor((end - start) / 1000);

    return `${diffInSeconds}s`;
};
const getStatusColor = (status: Deployment['status']) => {
    switch (status) {
        case 'succeeded':
            return 'bg-green-400';
        case 'failed':
            return 'bg-red-400';
        case 'running':
            return 'bg-blue-400';
        case 'pending':
            return 'bg-amber-400';
        default:
            return 'bg-gray-400';
    }
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

export default function DeploymentsIndex() {
    const {
        props: { currentEnvironment },
    } = usePage<SharedData>();
    const baseUrl = useBaseUrl();

    return (
        <EnvironmentLayout title={'Deployments'}>
            <div className="overflow-hidden rounded-lg border">
                {currentEnvironment?.deployments?.map((deployment, index) => (
                    <Link
                        href={`${baseUrl}deployments/${deployment.id}`}
                        key={deployment.id}
                        className={`bg-background flex flex-col p-4 md:flex-row md:items-center ${
                            index !== currentEnvironment?.deployments.length - 1 ? 'border-b' : ''
                        }`}
                    >
                        {/* ID and Environment */}
                        <div className="mb-3 min-w-0 flex-1 md:mb-0">
                            <div className="font-medium">{deployment.id.substring(5, 15)}</div>
                            <div className="text-muted-foreground flex items-center gap-2">
                                {currentEnvironment.name}
                                {deployment.is_latest && (
                                    <Badge variant="outline" className="bg-background text-xs">
                                        Current
                                    </Badge>
                                )}
                            </div>
                        </div>
                        {/* Status */}
                        <div className="mb-3 min-w-0 flex-1 md:mb-0">
                            <div className="flex items-center gap-2">
                                <span className={`inline-flex h-3 w-3 ${getStatusColor(deployment.status)} rounded-full`} />
                                <span>{getStatusDisplay(deployment.status)}</span>
                            </div>
                            <div className="text-muted-foreground text-sm">
                                {calculateBuildTime(deployment)} ({timeAgo(deployment.created_at)})
                            </div>
                        </div>
                        {/* Date */}
                        <div className="mb-3 min-w-0 flex-1 md:mb-0">
                            <div className="text-muted-foreground text-sm">{new Date(deployment.created_at).toLocaleDateString()}</div>
                        </div>
                        {/* Actions */}
                        {/* <div className="flex justify-end">
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="ghost" size="icon">
                                        <MoreHorizontal className="h-4 w-4" />
                                        <span className="sr-only">Open menu</span>
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem>View Deployment</DropdownMenuItem>
                                    <DropdownMenuItem>Redeploy</DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem>Copy URL</DropdownMenuItem>
                                    <DropdownMenuItem>Inspect Build Logs</DropdownMenuItem>
                                    <DropdownMenuItem>Inspect Runtime Logs</DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem className="text-red-500">Delete</DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div> */}
                    </Link>
                ))}

                {currentEnvironment?.deployments.length === 0 && (
                    <EmptyState title="No deployments" description="Deployments will appear here once you deploy your application." />
                )}
            </div>
        </EnvironmentLayout>
    );
}
