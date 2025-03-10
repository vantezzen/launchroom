import EnvironmentLayout from '@/layouts/environment-layout';
import { ProcessingUsage } from '@/types';
import MetricsDashboard from '../../components/features/metrics/MetricsDashboard';

export default function MetricsShow({ usage }: { usage: ProcessingUsage[] }) {
    return (
        <EnvironmentLayout title="Metrics">
            <MetricsDashboard service="app-laravel-demo-production@docker" usage={usage} />
        </EnvironmentLayout>
    );
}
