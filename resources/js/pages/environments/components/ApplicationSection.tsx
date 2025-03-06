import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Environment } from '@/types';
import { Calendar, Layers, Server } from 'lucide-react';
import { AppDetailItem, SectionHeader } from './shared';

export default function ApplicationSection({
    environment,
    appRef,
}: {
    environment: Environment;
    appRef: React.MutableRefObject<HTMLDivElement | null>;
}) {
    return (
        <div className="z-10 space-y-4">
            <SectionHeader icon={<Server className="h-4 w-4 text-gray-500" />} title="Application" />

            <Card ref={appRef}>
                <CardTitle className="flex items-center justify-between">
                    <span>App</span>
                    <div className="flex items-center space-x-2">
                        <Badge variant="outline" className="bg-white">
                            Web
                        </Badge>
                    </div>
                </CardTitle>
                <CardContent className="space-y-4">
                    <AppDetailItem
                        icon={<Layers className="h-4 w-4 text-gray-500" />}
                        label="Environment variables"
                        value={`${Object.keys(environment.environment_variables).length}`}
                    />
                    <AppDetailItem icon={<Calendar className="h-4 w-4 text-gray-500" />} label="Scheduler" value="Enabled" isEnabled={true} />
                    <AppDetailItem icon={<Calendar className="h-4 w-4 text-gray-500" />} label="Queue Worker" value="Enabled" isEnabled={true} />
                </CardContent>
            </Card>
        </div>
    );
}
