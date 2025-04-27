import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import EnvironmentLayout from '@/layouts/environment-layout';
import { usePoll } from '@inertiajs/react';
import { ChevronDown, ChevronRight, ChevronsUpDown, Search, TriangleAlert } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

// Define the structure of a log entry
interface LogEntry {
    source: string;
    service: string;
    timestamp: number;
    raw_timestamp: string;
    message: string;
    level: 'info' | 'warning' | 'error' | 'debug';
    environment?: string;
}

// Define log count structure
interface LogCounts {
    total: number;
    error: number;
    warning: number;
    info: number;
    debug: number;
}

export default function LogsShow({
    logs,
    logCounts = { total: 0, error: 0, warning: 0, info: 0, debug: 0 },
    error,
}: {
    logs: LogEntry[];
    logCounts?: LogCounts;
    error?: string;
}) {
    const [searchQuery, setSearchQuery] = useState('');
    const [levelFilter, setLevelFilter] = useState<string | null>(null);
    const [enabledSources, setEnabledSources] = useState<Record<string, boolean>>({});
    const [expandedLogs, setExpandedLogs] = useState<Record<string, boolean>>({});
    const logContainerRef = useRef<HTMLDivElement>(null);
    const [autoScroll, setAutoScroll] = useState(true);

    // Auto-refresh logs
    usePoll(5000);

    // Filter logs based on search query, level filter, and enabled sources
    const filteredLogs = useMemo(() => {
        const filtered = logs.filter((log) => {
            const matchesSearch =
                searchQuery === '' ||
                log.message.toLowerCase().includes(searchQuery.toLowerCase()) ||
                log.service.toLowerCase().includes(searchQuery.toLowerCase());

            const matchesLevel =
                !levelFilter ||
                levelFilter === 'all' ||
                (levelFilter === 'error' && log.level === 'error') ||
                (levelFilter === 'warning' && log.level === 'warning') ||
                (levelFilter === 'info' && log.level === 'info') ||
                (levelFilter === 'debug' && log.level === 'debug');

            const sourceEnabled = enabledSources[log.source] === true;

            return matchesSearch && matchesLevel && sourceEnabled;
        });

        // Sort by timestamp first, then by service name/source alphabetically for same timestamps
        return filtered.sort((a, b) => {
            // First sort by timestamp (ascending)
            if (a.timestamp !== b.timestamp) {
                return a.timestamp - b.timestamp;
            }

            // For same timestamps, sort by service name
            if (a.service !== b.service) {
                return a.service.localeCompare(b.service);
            }

            // If service names are the same, sort by source
            return a.source.localeCompare(b.source);
        });
    }, [logs, searchQuery, levelFilter, enabledSources]);

    // Initialize enabled sources when logs change
    useEffect(() => {
        const sources = new Set(logs.map((log) => log.source));
        const initialSources: Record<string, boolean> = {};

        // Enable all sources by default
        sources.forEach((source) => {
            // If source already exists in state, keep its value, otherwise set to true (enabled)
            initialSources[source] = enabledSources[source] !== undefined ? enabledSources[source] : true;
        });

        setEnabledSources(initialSources);
    }, [logs]);

    // Reset expanded logs when logs change
    useEffect(() => {
        // Initially expand error logs
        const initialExpanded: Record<string, boolean> = {};
        logs.forEach((log, index) => {
            if (log.level === 'error') {
                initialExpanded[index] = true;
            }
        });
        setExpandedLogs(initialExpanded);
    }, [logs]);

    // Auto-scroll to bottom when logs change if auto-scroll is enabled
    useEffect(() => {
        if (autoScroll && logContainerRef.current) {
            logContainerRef.current.scrollTop = logContainerRef.current.scrollHeight;
        }
    }, [filteredLogs, autoScroll]);

    // Detect manual scroll to disable auto-scroll
    const handleScroll = () => {
        if (!logContainerRef.current) return;

        const { scrollTop, scrollHeight, clientHeight } = logContainerRef.current;
        const isAtBottom = scrollTop + clientHeight >= scrollHeight - 50;

        // If user scrolled up, disable auto-scroll. If they scrolled to bottom, re-enable it
        setAutoScroll(isAtBottom);
    };

    // Toggle a log's expanded state
    const toggleExpand = (index: number) => {
        setExpandedLogs((prev) => ({
            ...prev,
            [index]: !prev[index],
        }));
    };

    // Toggle a source's enabled state
    const toggleSource = (source: string) => {
        setEnabledSources((prev) => ({
            ...prev,
            [source]: !prev[source],
        }));
    };

    // Get background color based on log level
    const getLogBackground = (level: string) => {
        switch (level) {
            case 'error':
                return 'bg-red-500/10 text-red-500';
            case 'warning':
                return 'bg-amber-500/10 text-amber-500';
            case 'debug':
                return 'bg-slate-500/10 text-slate-500';
            default:
                return '';
        }
    };

    // Get badge color based on source type
    const getSourceBadge = (source: string) => {
        switch (source) {
            case 'laravel':
                return 'bg-red-500/20 text-red-700 hover:bg-red-500/30';
            case 'docker':
                return 'bg-blue-500/20 text-blue-700 hover:bg-blue-500/30';
            default:
                return 'bg-gray-500/20 text-gray-700 hover:bg-gray-500/30';
        }
    };

    // Format the timestamp for display
    const formatTimestamp = (timestamp: number) => {
        return new Date(timestamp * 1000).toLocaleString();
    };

    // Count logs by source
    const sourceCounts = useMemo(() => {
        const counts: Record<string, number> = {};
        logs.forEach((log) => {
            counts[log.source] = (counts[log.source] || 0) + 1;
        });
        return counts;
    }, [logs]);

    // Count enabled sources
    const enabledSourcesCount = useMemo(() => {
        return Object.values(enabledSources).filter(Boolean).length;
    }, [enabledSources]);

    // Get all available sources
    const allSources = useMemo(() => {
        return Object.keys(sourceCounts);
    }, [sourceCounts]);

    return (
        <EnvironmentLayout title="Logs">
            {error && (
                <Alert variant="destructive" className="mb-4">
                    <TriangleAlert className="mr-2 h-4 w-4" />
                    <AlertTitle>You have no active deployments</AlertTitle>
                    <AlertDescription>
                        You can trigger a deployment from the overview page. Please make sure you have a deployment configured in order to view its
                        logs.
                    </AlertDescription>
                </Alert>
            )}

            <div className="bg-background mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 p-3">
                {/* Level filter dropdown */}
                <Select value={levelFilter || ''} onValueChange={(value) => setLevelFilter(value || null)}>
                    <SelectTrigger className="w-[180px]">
                        <SelectValue placeholder="All Log Levels" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Levels ({logCounts.total})</SelectItem>
                        <SelectItem value="error">Errors ({logCounts.error})</SelectItem>
                        <SelectItem value="warning">Warnings ({logCounts.warning})</SelectItem>
                        <SelectItem value="info">Info Logs ({logCounts.total - logCounts.error - logCounts.warning - logCounts.debug})</SelectItem>
                        <SelectItem value="debug">Debug ({logCounts.debug})</SelectItem>
                    </SelectContent>
                </Select>

                {/* Source filter dropdown */}
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="outline" className="flex items-center gap-2">
                            <span>
                                Sources ({enabledSourcesCount}/{allSources.length})
                            </span>
                            <ChevronsUpDown className="h-4 w-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent>
                        {allSources.map((source) => (
                            <DropdownMenuCheckboxItem
                                key={source}
                                checked={enabledSources[source]}
                                onCheckedChange={() => toggleSource(source)}
                                className="capitalize"
                            >
                                {source} ({sourceCounts[source]})
                            </DropdownMenuCheckboxItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>

                {/* Auto-scroll toggle */}
                <div className="flex items-center gap-2">
                    <Checkbox id="auto-scroll" checked={autoScroll} onCheckedChange={(checked) => setAutoScroll(checked as boolean)} />
                    <Label htmlFor="auto-scroll">Auto-scroll</Label>
                </div>

                <div className="ml-auto flex items-center gap-2">
                    <div className="relative">
                        <Search className="text-muted-foreground absolute top-1/2 left-2 h-4 w-4 -translate-y-1/2 transform" />
                        <Input
                            placeholder="Search logs"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="h-8 w-[200px] pl-8"
                        />
                    </div>
                </div>
            </div>

            <div className="bg-background w-full rounded-lg border border-gray-200">
                <div ref={logContainerRef} className="max-h-[80vh] overflow-auto p-0" onScroll={handleScroll}>
                    {filteredLogs.length === 0 ? (
                        <div className="p-4 text-center text-gray-500">No logs found</div>
                    ) : (
                        <div className="font-mono text-sm">
                            {filteredLogs.map((log, index) => {
                                const isExpanded = expandedLogs[index] || false;
                                const hasLongMessage = log.message.length > 500 || log.message.includes('\n');

                                // Get first line and truncate if needed
                                let firstLine = log.message;
                                let restOfMessage = '';

                                if (hasLongMessage) {
                                    if (log.message.includes('\n')) {
                                        const lines = log.message.split('\n');
                                        firstLine = lines[0];
                                        restOfMessage = lines.slice(1).join('\n');
                                    } else {
                                        firstLine = log.message.substring(0, 500);
                                        restOfMessage = log.message.substring(500);
                                    }
                                }

                                return (
                                    <div key={index} className={`border-b border-gray-100 ${getLogBackground(log.level)}`}>
                                        <div
                                            className="flex cursor-pointer items-start p-2 hover:bg-gray-50"
                                            onClick={() => hasLongMessage && toggleExpand(index)}
                                        >
                                            {hasLongMessage && (
                                                <div className="mt-1 mr-2">
                                                    {isExpanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                                </div>
                                            )}

                                            <div className="flex-1">
                                                <div className="flex items-center gap-2">
                                                    <div className="text-xs whitespace-nowrap text-zinc-400">{formatTimestamp(log.timestamp)}</div>

                                                    <Badge className={`${getSourceBadge(log.source)} text-xs`} variant="outline">
                                                        {log.source}
                                                    </Badge>

                                                    <div className="text-xs font-bold whitespace-nowrap">{log.service}</div>

                                                    {log.level !== 'info' && (
                                                        <Badge
                                                            variant={log.level === 'error' ? 'destructive' : 'outline'}
                                                            className={log.level === 'warning' ? 'bg-amber-500 text-white' : ''}
                                                        >
                                                            {log.level}
                                                        </Badge>
                                                    )}
                                                </div>

                                                <div className="mt-1 break-all">
                                                    {firstLine}
                                                    {hasLongMessage && !isExpanded && (
                                                        <span className="text-muted-foreground ml-2 italic">
                                                            {log.message.includes('\n')
                                                                ? `... (${log.message.split('\n').length - 1} more lines)`
                                                                : `... (${log.message.length - 500} more characters)`}
                                                        </span>
                                                    )}
                                                    {isExpanded && restOfMessage && (
                                                        <div className="mt-2 border-l-2 border-gray-200 pl-2 whitespace-pre-wrap">
                                                            {restOfMessage}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>

                <div className="text-muted-foreground border-t border-gray-200 p-2 text-xs">
                    Last updated: {new Date().toLocaleTimeString()}
                    {autoScroll && <span className="ml-2">(Auto-scrolling enabled)</span>}
                </div>
            </div>
        </EnvironmentLayout>
    );
}
