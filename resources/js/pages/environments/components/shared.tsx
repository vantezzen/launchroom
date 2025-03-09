export function StatusIndicator({ status, label }: { status: 'active' | 'inactive'; label?: string }) {
    return (
        <div className="flex items-center space-x-2">
            <div className={`h-2 w-2 rounded-full ${status === 'active' ? 'bg-green-500' : 'bg-gray-300'}`} />
            {label && <span className={`text-sm ${status === 'active' ? 'text-green-500' : 'text-gray-500'}`}>{label}</span>}
        </div>
    );
}

export function SectionHeader({ icon, title }: { icon: React.ReactNode; title: string }) {
    return (
        <div className="flex items-center space-x-2 rounded-lg border bg-white px-4 py-2">
            {icon}
            <h2 className="text-sm font-medium text-gray-700">{title}</h2>
        </div>
    );
}

export function AppDetailItem({
    icon,
    label,
    value,
    isEnabled,
    action,
}: {
    icon: React.ReactNode;
    label: string;
    value: string;
    isEnabled?: boolean;
    action?: React.ReactNode;
}) {
    return (
        <div className="flex items-center justify-between">
            <div className="flex flex-1 items-center space-x-2">
                {icon}
                <span className="text-sm text-gray-600">{label}</span>
            </div>
            <div className="flex items-center space-x-2 text-right">
                {isEnabled !== undefined && <StatusIndicator status={isEnabled ? 'active' : 'inactive'} />}
                <span className="text-sm font-medium">{value}</span>
            </div>
            {action && <div className="ml-2">{action}</div>}
        </div>
    );
}
