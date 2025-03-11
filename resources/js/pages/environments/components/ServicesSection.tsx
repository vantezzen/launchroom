import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Service } from '@/types';
import { useForm } from '@inertiajs/react';
import { ChevronRight, Database, FileText, Layers, Menu, Trash2 } from 'lucide-react';
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

const ServiceCard = forwardRef(function ServiceCard({ service }: { service: Service }, ref: React.Ref<HTMLDivElement>) {
    const { data, setData, delete: destroy, errors, processing } = useForm();

    const handleDelete = () => {
        destroy(route('services.destroy', { service: service.id }));
    };

    const [isEditingEnvVars, setIsEditingEnvVars] = useState(false);
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

                    <DropdownMenu>
                        <DropdownMenuTrigger>
                            <Button size="xs" variant="ghost">
                                <Menu className="h-2 w-2 text-gray-700" />
                            </Button>
                        </DropdownMenuTrigger>

                        <DropdownMenuContent className="w-56">
                            <DropdownMenuLabel>Actions</DropdownMenuLabel>

                            <DropdownMenuGroup>
                                <DropdownMenuItem
                                    variant="destructive"
                                    onClick={() => {
                                        if (
                                            confirm(
                                                'Are you sure you want to delete this service? This will PERMANENTLY delete all data associated with this service.',
                                            )
                                        ) {
                                            handleDelete();
                                        }
                                    }}
                                    disabled={processing}
                                >
                                    <Trash2 />
                                    <span>Delete</span>
                                </DropdownMenuItem>
                            </DropdownMenuGroup>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </CardTitle>
            <CardContent className="space-y-4">
                <AppDetailItem icon={<FileText className="h-4 w-4 text-gray-500" />} label="Name" value={service.name} />
                <AppDetailItem
                    icon={<Layers className="h-4 w-4 text-gray-500" />}
                    label="Environment variables"
                    value={`${Object.keys(service.environment_variables).length}`}
                    action={
                        <Button size="xs" variant="secondary" onClick={() => setIsEditingEnvVars(!isEditingEnvVars)}>
                            <ChevronRight className="h-2 w-2 text-gray-700" />
                        </Button>
                    }
                />
            </CardContent>

            {isEditingEnvVars && (
                <EnvironmentVariablePanel
                    isOpen={isEditingEnvVars}
                    onClose={() => setIsEditingEnvVars(false)}
                    initialVariables={service.environment_variables}
                    allowEdit={false}
                    title={`${service.name} Environment variables`}
                />
            )}
        </Card>
    );
});
