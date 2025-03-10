import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { ProcessingUsage } from '@/types';
import { formatBytes, formatPercentage } from '@/utils/number';
import { DiskIOChart, NetworkChart } from './ProcessingCharts';
import { UsageAreaChart } from './UsageAreaChart';

function ProcessingUsageDashboard({ usageData }: { usageData: ProcessingUsage[] }) {
    // Sort data by timestamp (newest first)
    const sortedData = [...usageData].sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime());

    // Get the most recent data point for current values
    const currentData = sortedData[0] || {
        cpu: 0,
        mem_usage: 0,
        mem_limit: 0,
        mem_percent: 0,
        net_in: 0,
        net_out: 0,
        block_in: 0,
        block_out: 0,
    };

    return (
        <div className="space-y-6">
            <div className="flex flex-col space-y-2">
                <h2 className="text-2xl font-bold tracking-tight">Resource Usage</h2>
            </div>
            <UsageMetricsGrid usageData={sortedData} currentData={currentData} />
        </div>
    );
}

export default ProcessingUsageDashboard;

interface UsageMetricsGridProps {
    usageData: ProcessingUsage[];
    currentData: Partial<ProcessingUsage>;
}

function UsageMetricsGrid({ usageData, currentData }: UsageMetricsGridProps) {
    return (
        <>
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <MetricCard title="CPU Usage" value={`${formatPercentage(currentData.cpu || 0)}`} description="Current CPU utilization" />
                <MetricCard
                    title="Memory Usage"
                    value={formatBytes(currentData.mem_usage || 0)}
                    description={`${formatPercentage(currentData.mem_percent || 0)} of ${formatBytes(currentData.mem_limit || 0)}`}
                />
                <MetricCard
                    title="Network Traffic"
                    value={formatBytes(currentData.net_in || 0)}
                    description={`${formatBytes(currentData.net_out || 0)} out`}
                    valueLabel="in"
                    descriptionLabel="out"
                />
                <MetricCard
                    title="Disk I/O"
                    value={formatBytes(currentData.block_in || 0)}
                    description={`${formatBytes(currentData.block_out || 0)} written`}
                    valueLabel="read"
                    descriptionLabel="written"
                />
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <Card>
                    <CardTitle>CPU Usage</CardTitle>
                    <CardContent>
                        <div className="h-[300px]">
                            <UsageAreaChart
                                data={usageData}
                                dataKey="cpu"
                                stroke="#0ea5e9"
                                fill="#c4ebfd"
                                yAxisFormatter={(value) => `${value}%`}
                                tooltipFormatter={(value) => `${value.toFixed(2)}%`}
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardTitle>Memory Usage</CardTitle>
                    <CardContent>
                        <div className="h-[300px]">
                            <UsageAreaChart
                                data={usageData}
                                dataKey="mem_usage"
                                limitKey="mem_limit"
                                stroke="#8b5cf6"
                                fill="#c4b5fd"
                                yAxisFormatter={formatBytes}
                                tooltipFormatter={formatBytes}
                            />
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <Card>
                    <CardTitle>Network Traffic</CardTitle>
                    <CardContent>
                        <div className="h-[300px]">
                            <NetworkChart data={usageData} />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardTitle>Disk I/O</CardTitle>
                    <CardContent>
                        <div className="h-[300px]">
                            <DiskIOChart data={usageData} />
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

interface MetricCardProps {
    title: string;
    value: string;
    description: string;
    valueLabel?: string;
    descriptionLabel?: string;
}

function MetricCard({ title, value, description, valueLabel, descriptionLabel }: MetricCardProps) {
    return (
        <Card>
            <CardTitle className="text-sm font-medium">{title}</CardTitle>
            <CardContent>
                <div className="text-2xl font-bold">
                    {value} {valueLabel && <span className="text-muted-foreground text-xs">{valueLabel}</span>}
                </div>
                <p className="text-muted-foreground text-xs">
                    {description} {descriptionLabel && <span>{descriptionLabel}</span>}
                </p>
            </CardContent>
        </Card>
    );
}
