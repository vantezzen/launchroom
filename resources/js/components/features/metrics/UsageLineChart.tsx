import { ProcessingUsage } from '@/types';
import { format } from 'date-fns';
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

interface DataKeyConfig {
    key: string;
    name: string;
    stroke: string;
}

interface UsageLineChartProps {
    data: ProcessingUsage[];
    dataKey?: string;
    multipleDataKeys?: DataKeyConfig[];
    stroke?: string;
    gradient?: boolean;
    yAxisFormatter?: (value: number) => string;
    tooltipFormatter?: (value: number) => string;
}

export function UsageLineChart({
    data,
    dataKey,
    multipleDataKeys,
    stroke = '#8884d8',
    gradient = false,
    yAxisFormatter = (value) => `${value}`,
    tooltipFormatter = (value) => `${value}`,
}: UsageLineChartProps) {
    // Format data for the chart
    const chartData = data.map((item) => ({
        ...item,
        timestamp: new Date(item.created_at).getTime(),
    }));

    // Sort by timestamp (oldest first for chart display)
    chartData.sort((a, b) => a.timestamp - b.timestamp);

    // Generate a unique ID for the gradient
    const gradientId = `colorGradient-${dataKey || 'multi'}`;

    return (
        <ResponsiveContainer width="100%" height="100%">
            <LineChart
                data={chartData}
                margin={{
                    top: 5,
                    right: 30,
                    left: 20,
                    bottom: 5,
                }}
            >
                {gradient && dataKey && (
                    <defs>
                        <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                            <stop offset="5%" stopColor={stroke} stopOpacity={0.8} />
                            <stop offset="95%" stopColor={stroke} stopOpacity={0} />
                        </linearGradient>
                    </defs>
                )}
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                <XAxis dataKey="timestamp" tickFormatter={(timestamp) => format(new Date(timestamp), 'HH:mm')} minTickGap={60} />
                <YAxis tickFormatter={yAxisFormatter} />
                <Tooltip
                    labelFormatter={(timestamp) => format(new Date(timestamp), 'MMM dd, yyyy HH:mm:ss')}
                    formatter={(value: number, name: string) => [tooltipFormatter(value), name]}
                />
                {multipleDataKeys ? (
                    <>
                        <Legend />
                        {multipleDataKeys.map((config) => (
                            <Line
                                key={config.key}
                                type="monotone"
                                dataKey={config.key}
                                name={config.name}
                                stroke={config.stroke}
                                activeDot={{ r: 8 }}
                                dot={false}
                            />
                        ))}
                    </>
                ) : (
                    dataKey && (
                        <Line
                            type="monotone"
                            dataKey={dataKey}
                            stroke={stroke}
                            activeDot={{ r: 8 }}
                            dot={false}
                            strokeWidth={2}
                            {...(gradient && { fill: `url(#${gradientId})` })}
                        />
                    )
                )}
            </LineChart>
        </ResponsiveContainer>
    );
}
