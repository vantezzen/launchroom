import EnvironmentLayout from '@/layouts/environment-layout';
import MetricsDashboard from '../../components/features/metrics/MetricsDashboard';

export default function MetricsShow() {
    return (
        <EnvironmentLayout title="Metrics">
            <MetricsDashboard service="app-laravel-demo-production@docker" />
        </EnvironmentLayout>
    );
}
