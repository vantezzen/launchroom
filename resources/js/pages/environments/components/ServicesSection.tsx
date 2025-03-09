import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Service } from '@/types';
import { ChevronRight, Database, FileText, Layers } from 'lucide-react';
import { forwardRef, useState } from 'react';
import { EnvironmentVariablePanel } from './EnvironmentVariablesPanel';
import AddServiceModal from './services/AddServiceModal';
import { AppDetailItem, SectionHeader } from './shared';

export default function ServicesSection({ services, servicesRef }: { services: Service[]; servicesRef: React.MutableRefObject<HTMLDivElement[]> }) {
    // Group services by type
    const servicesByType: Record<string, Service[]> = {
        database: services.filter((s) => s.category === 'database'),
        cache: services.filter((s) => s.category === 'cache'),
        disk: services.filter((s) => s.category === 'disk'),
    };

    return (
        <div className="z-10 space-y-4">
            <SectionHeader icon={<Database className="h-4 w-4 text-gray-500" />} title="Services" />

            {/* Database Services */}
            {servicesByType.database.map((service, index) => (
                <ServiceCard
                    key={service.id}
                    service={service}
                    ref={(el) => {
                        servicesRef.current[index] = el as HTMLDivElement;
                    }}
                />
            ))}

            {/* Cache Services */}
            {servicesByType.cache.map((service, index) => (
                <ServiceCard
                    key={service.id}
                    service={service}
                    ref={(el) => {
                        servicesRef.current[index + servicesByType.database.length] = el as HTMLDivElement;
                    }}
                />
            ))}

            {/* Disk Services */}
            {servicesByType.disk.map((service, index) => (
                <ServiceCard
                    key={service.id}
                    service={service}
                    ref={(el) => {
                        servicesRef.current[index + servicesByType.database.length + servicesByType.cache.length] = el as HTMLDivElement;
                    }}
                />
            ))}

            <AddServiceModal />
        </div>
    );
}

function EmptyServiceCard({ icon, title, description }: { icon: React.ReactNode; title: string; description: string }) {
    return (
        <div className="flex flex-col items-center justify-center space-y-2 rounded-lg border border-dashed border-gray-200 bg-white p-6">
            {icon}
            <h3 className="font-medium text-gray-700">{title}</h3>
            <p className="text-sm text-gray-500">{description}</p>
        </div>
    );
}

const ServiceCard = forwardRef(function ServiceCard({ service }: { service: Service }, ref: React.Ref<HTMLDivElement>) {
    const [isEditing, setIsEditing] = useState(false);
    const getServiceTypeBadge = () => {
        switch (service.category) {
            case 'database':
                return 'Database';
            case 'cache':
                return 'Cache';
            case 'disk':
                return 'Bucket';
            default:
                return service.category;
        }
    };

    const getBadgeColor = () => {
        switch (service.category) {
            case 'database':
                return 'bg-amber-50 text-amber-700 border-amber-200';
            case 'cache':
                return 'bg-purple-50 text-purple-700 border-purple-200';
            case 'disk':
                return 'bg-indigo-50 text-indigo-700 border-indigo-200';
            default:
                return 'bg-gray-50 text-gray-700 border-gray-200';
        }
    };

    return (
        <Card ref={ref}>
            <CardTitle className="flex items-center justify-between">
                <span className="truncate">{service.name}</span>
                <div className="flex items-center space-x-2">
                    <Badge className={getBadgeColor()}>{getServiceTypeBadge()}</Badge>
                </div>
            </CardTitle>
            <CardContent className="space-y-4">
                <AppDetailItem icon={<FileText className="h-4 w-4 text-gray-500" />} label="Name" value={service.name} />
                <AppDetailItem
                    icon={<Layers className="h-4 w-4 text-gray-500" />}
                    label="Environment variables"
                    value={`${Object.keys(service.environment_variables).length}`}
                    action={
                        <Button size="xs" variant="secondary" onClick={() => setIsEditing(!isEditing)}>
                            <ChevronRight className="h-2 w-2 text-gray-700" />
                        </Button>
                    }
                />
            </CardContent>

            {isEditing && (
                <EnvironmentVariablePanel
                    isOpen={isEditing}
                    onClose={() => setIsEditing(false)}
                    initialVariables={service.environment_variables}
                    allowEdit={false}
                    title={`${service.name} Environment variables`}
                />
            )}
        </Card>
    );
});
