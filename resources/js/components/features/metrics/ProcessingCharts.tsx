import { ProcessingUsage } from '@/types';
import { formatBytes } from '@/utils/number';
import { format } from 'date-fns';
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

export function NetworkChart({ data }: { data: ProcessingUsage[] }) {
    return (
        <ResponsiveContainer width="100%" height="100%">
            <LineChart
                data={data.map((item) => ({ ...item, timestamp: new Date(item.created_at).getTime() })).reverse()}
                margin={{ top: 5, right: 30, left: 20, bottom: 5 }}
            >
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                <XAxis dataKey="timestamp" tickFormatter={(timestamp) => format(new Date(timestamp), 'HH:mm')} minTickGap={60} />
                <YAxis tickFormatter={formatBytes} />
                <Tooltip
                    labelFormatter={(timestamp) => format(new Date(timestamp), 'MMM dd, yyyy HH:mm:ss')}
                    formatter={(value: number, name: string) => [formatBytes(value), name === 'net_in' ? 'Network In' : 'Network Out']}
                />
                <Legend />
                <Line type="monotone" dataKey="net_in" name="In" stroke="#10b981" activeDot={{ r: 8 }} dot={false} strokeWidth={2} />
                <Line type="monotone" dataKey="net_out" name="Out" stroke="#f97316" activeDot={{ r: 8 }} dot={false} strokeWidth={2} />
            </LineChart>
        </ResponsiveContainer>
    );
}

export function DiskIOChart({ data }: { data: ProcessingUsage[] }) {
    return (
        <ResponsiveContainer width="100%" height="100%">
            <LineChart
                data={data.map((item) => ({ ...item, timestamp: new Date(item.created_at).getTime() })).reverse()}
                margin={{ top: 5, right: 30, left: 20, bottom: 5 }}
            >
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                <XAxis dataKey="timestamp" tickFormatter={(timestamp) => format(new Date(timestamp), 'HH:mm')} minTickGap={60} />
                <YAxis tickFormatter={formatBytes} />
                <Tooltip
                    labelFormatter={(timestamp) => format(new Date(timestamp), 'MMM dd, yyyy HH:mm:ss')}
                    formatter={(value: number, name: string) => [formatBytes(value), name === 'block_in' ? 'Read' : 'Write']}
                />
                <Legend />
                <Line type="monotone" dataKey="block_in" name="Read" stroke="#06b6d4" activeDot={{ r: 8 }} dot={false} strokeWidth={2} />
                <Line type="monotone" dataKey="block_out" name="Write" stroke="#d946ef" activeDot={{ r: 8 }} dot={false} strokeWidth={2} />
            </LineChart>
        </ResponsiveContainer>
    );
}
