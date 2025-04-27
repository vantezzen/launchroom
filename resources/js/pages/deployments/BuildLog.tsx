import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';

interface LogEntry {
    timestamp: string;
    prefix: 'log' | 'error' | 'warning';
    content: string;
    isSection: boolean;
}

interface LogGroup {
    entries: LogEntry[];
}

interface BuildLogsProps {
    output: string;
}

export function BuildLogs({ output }: BuildLogsProps) {
    const [searchQuery, setSearchQuery] = useState('');

    // Parse the log output into structured data
    const parseLogOutput = (output: string): LogEntry[] => {
        return output
            .split('\n')
            .map((line) => {
                const match = line.match(/\[(.*?)\]\[(.*?)\] (.*)/);
                if (!match) return null;

                const [_, timestamp, prefix, content] = match;
                return {
                    timestamp,
                    prefix: prefix as 'log' | 'error' | 'warning',
                    content: content.trim(),
                    isSection: content.startsWith('## '),
                };
            })
            .filter(Boolean) as LogEntry[];
    };

    // Group consecutive logs of the same type
    const groupLogs = (logs: LogEntry[]): LogGroup[] => {
        const groups: LogGroup[] = [];
        let currentGroup: LogEntry[] = [];

        logs.forEach((log) => {
            if (log.isSection || !currentGroup.length || currentGroup[0].prefix !== log.prefix || currentGroup[0].isSection) {
                if (currentGroup.length) {
                    groups.push({ entries: currentGroup });
                }
                currentGroup = [log];
            } else {
                currentGroup.push(log);
            }
        });

        if (currentGroup.length) {
            groups.push({ entries: currentGroup });
        }

        return groups;
    };

    const logs = parseLogOutput(output);
    const logGroups = useMemo(() => groupLogs(logs), [logs]);

    // Get background color based on log prefix
    const getLogBackground = (prefix: string) => {
        switch (prefix) {
            case 'error':
                return 'bg-red-500/10 text-red-500';
            case 'warning':
                return 'bg-amber-500/10 text-amber-500';
            default:
                return '';
        }
    };

    // Filter logs based on search query
    const filteredGroups = logGroups.filter((group) =>
        group.entries.some((entry) => entry.content.toLowerCase().includes(searchQuery.toLowerCase())),
    );

    // Count total logs, errors, and warnings
    const counts = logs.reduce(
        (acc, log) => {
            acc.total++;
            if (log.prefix === 'error') acc.errors++;
            if (log.prefix === 'warning') acc.warnings++;
            return acc;
        },
        { total: 0, errors: 0, warnings: 0 },
    );

    return (
        <div>
            <div className="flex items-center gap-2 border-b p-2">
                <Badge>All Logs ({counts.total})</Badge>
                <Badge variant="destructive">Errors ({counts.errors})</Badge>
                <Badge className="bg-amber-500">Warnings ({counts.warnings})</Badge>
                <div className="ml-auto flex items-center gap-2">
                    <div className="relative">
                        <Search className="text-muted-foreground absolute top-1/2 left-2 h-4 w-4 -translate-y-1/2 transform" />
                        <Input
                            placeholder="Find in logs"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="h-8 w-[200px] pl-8"
                        />
                    </div>
                </div>
            </div>

            <div className="p-4 font-mono text-sm">
                {filteredGroups.map((group, groupIndex) => (
                    <div key={groupIndex}>
                        {group.entries.slice(0, undefined).map((entry, entryIndex) => (
                            <div key={entryIndex} className={`flex gap-4 ${getLogBackground(entry.prefix)}`}>
                                <div className="bg-zinc-50 p-2 whitespace-nowrap text-zinc-400 select-none dark:bg-zinc-800">{entry.timestamp}</div>
                                <div className={`${entry.isSection ? 'font-bold' : ''} p-2 break-all whitespace-pre-wrap`}>{entry.content}</div>
                            </div>
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );
}
