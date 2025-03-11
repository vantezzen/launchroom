import EnvironmentLayout from '@/layouts/environment-layout';
import { ProcessingUsage } from '@/types';
import { usePoll } from '@inertiajs/react';
import { useMemo } from 'react';
import MetricsDashboard from '../../components/features/metrics/MetricsDashboard';

export default function MetricsShow({ usage }: { usage: ProcessingUsage[] }) {
    const sortedUsage = useMemo(() => usage.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime()), [usage]);
    usePoll(1000 * 30);

    return (
        <EnvironmentLayout title="Metrics">
            <MetricsDashboard service="app-laravel-demo-production@docker" usage={sortedUsage} />
        </EnvironmentLayout>
    );
}
