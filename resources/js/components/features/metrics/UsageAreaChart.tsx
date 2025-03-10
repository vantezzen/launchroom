'use client';
import { ProcessingUsage } from '@/types';
import { format } from 'date-fns';
import { Area, AreaChart, CartesianGrid, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

interface UsageAreaChartProps {
    data: ProcessingUsage[];
    dataKey: string;
    limitKey?: string;
    stroke?: string;
    fill?: string;
    yAxisFormatter?: (value: number) => string;
    tooltipFormatter?: (value: number) => string;
}

export function UsageAreaChart({
    data,
    dataKey,
    limitKey,
    stroke = '#8884d8',
    fill = '#8884d8',
    yAxisFormatter = (value) => `${value}`,
    tooltipFormatter = (value) => `${value}`,
}: UsageAreaChartProps) {
    // Format data for the chart
    const chartData = data.map((item) => ({
        ...item,
        timestamp: new Date(item.created_at).getTime(),
    }));

    // Sort by timestamp (oldest first for chart display)
    chartData.sort((a, b) => a.timestamp - b.timestamp);

    // Get the limit value (assuming it's constant)
    const limitValue = limitKey && chartData.length > 0 ? chartData[0][limitKey as keyof ProcessingUsage] : undefined;

    // Generate a unique ID for the gradient
    const gradientId = `colorGradient-${dataKey}`;

    return (
        <ResponsiveContainer width="100%" height="100%">
            <AreaChart
                data={chartData}
                margin={{
                    top: 10,
                    right: 30,
                    left: 0,
                    bottom: 0,
                }}
            >
                <defs>
                    <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="5%" stopColor={fill} stopOpacity={0.8} />
                        <stop offset="95%" stopColor={fill} stopOpacity={0.1} />
                    </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                <XAxis dataKey="timestamp" tickFormatter={(timestamp) => format(new Date(timestamp), 'HH:mm')} minTickGap={60} />
                <YAxis tickFormatter={yAxisFormatter} />
                <Tooltip
                    labelFormatter={(timestamp) => format(new Date(timestamp), 'MMM dd, yyyy HH:mm:ss')}
                    formatter={(value: number, name: string) => [tooltipFormatter(value), name === dataKey ? 'Usage' : name]}
                />
                {limitValue && typeof limitValue === 'number' && (
                    <ReferenceLine y={limitValue} label="Limit" stroke="red" strokeDasharray="3 3" isFront={true} />
                )}
                <Area type="monotone" dataKey={dataKey} stroke={stroke} fillOpacity={1} fill={`url(#${gradientId})`} />
            </AreaChart>
        </ResponsiveContainer>
    );
}
