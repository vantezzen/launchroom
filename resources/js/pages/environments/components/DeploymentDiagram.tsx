import { AnimatedBeam } from '@/components/ui/animated-beam';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Environment, Service } from '@/types';
import { Box, Calendar, Clock, Database, FileText, Globe, HardDrive, Layers, MoreHorizontal, PlusCircle, Server, Shield, Zap } from 'lucide-react';
import type React from 'react';
import { forwardRef, useRef } from 'react';

export default function DeploymentDiagram({ environment }: { environment: Environment }) {
    const containerRef = useRef<HTMLDivElement>(null);
    const networkRef = useRef<HTMLDivElement>(null);
    const appRef = useRef<HTMLDivElement>(null);

    return (
        <div className="bg-grid-pattern min-h-screen w-full">
            <div className="relative container mx-auto p-6" ref={containerRef}>
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Network Section */}
                    <NetworkSection domains={environment.domains} networkRef={networkRef} />

                    {/* Application Section */}
                    <ApplicationSection environment={environment} appRef={appRef} />

                    {/* Services Section */}
                    <ServicesSection services={environment.services} />
                </div>
                <AnimatedBeam duration={1} containerRef={containerRef} fromRef={networkRef} toRef={appRef} />
            </div>
        </div>
    );
}

// Network Section Component
function NetworkSection({ domains, networkRef }: { domains: string[]; networkRef: React.MutableRefObject<HTMLDivElement | null> }) {
    return (
        <div className="z-10 space-y-4">
            <SectionHeader icon={<Globe className="h-4 w-4 text-gray-500" />} title="Network" />

            <Card ref={networkRef}>
                <CardHeader>Edge network</CardHeader>
                <CardContent>
                    <EdgeNetworkItem icon={<Shield className="h-4 w-4 text-gray-500" />} label="DDoS Protection" status="active" />
                    <EdgeNetworkItem icon={<Globe className="h-4 w-4 text-gray-500" />} label="CDN" status="active" />
                    <EdgeNetworkItem icon={<Zap className="h-4 w-4 text-gray-500" />} label="Edge Caching" status="active" />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>Domains</CardHeader>
                <CardContent>
                    {domains.map((domain, index) => (
                        <DomainItem key={index} domain={domain} isCloudDomain={domain.includes('my-app.com')} />
                    ))}
                    <AddButton label="Add domain" />
                </CardContent>
            </Card>
        </div>
    );
}

// Application Section Component
function ApplicationSection({ environment, appRef }: { environment: Environment; appRef: React.MutableRefObject<HTMLDivElement | null> }) {
    return (
        <div className="z-10 space-y-4">
            <SectionHeader icon={<Server className="h-4 w-4 text-gray-500" />} title="Application" />

            <Card ref={appRef}>
                <CardHeader className="flex items-center justify-between">
                    <span>App</span>
                    <div className="flex items-center space-x-2">
                        <Badge variant="outline" className="bg-white">
                            Web
                        </Badge>
                        <Button variant="ghost" size="icon" className="h-8 w-8">
                            <MoreHorizontal className="h-4 w-4" />
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <AppDetailItem icon={<Server className="h-4 w-4 text-gray-500" />} label="Size" value="Flex 1 vCPU" />
                    <AppDetailItem
                        icon={<Layers className="h-4 w-4 text-gray-500" />}
                        label="Environment variables"
                        value={`${Object.keys(environment.environment_variables).length} variables`}
                    />
                    <AppDetailItem icon={<Calendar className="h-4 w-4 text-gray-500" />} label="Scheduler" value="Enabled" isEnabled={true} />
                </CardContent>
            </Card>

            <AddButton label="Add worker cluster" />
        </div>
    );
}

// Services Section Component
function ServicesSection({ services }: { services: Service[] }) {
    // Group services by type
    const servicesByType: Record<string, Service[]> = {
        database: services.filter((s) => s.category === 'database'),
        cache: services.filter((s) => s.category === 'cache'),
        disk: services.filter((s) => s.category === 'disk'),
    };

    return (
        <div className="space-y-4">
            <SectionHeader icon={<Database className="h-4 w-4 text-gray-500" />} title="Services" />

            {/* Database Services */}
            {servicesByType.database.length > 0 ? (
                servicesByType.database.map((service) => <ServiceCard key={service.id} service={service} />)
            ) : (
                <EmptyServiceCard
                    icon={<Database className="h-6 w-6 text-gray-400" />}
                    title="Add database"
                    description="Create or select a database"
                />
            )}

            {/* Cache Services */}
            {servicesByType.cache.length > 0 ? (
                servicesByType.cache.map((service) => <ServiceCard key={service.id} service={service} />)
            ) : (
                <EmptyServiceCard icon={<Clock className="h-6 w-6 text-gray-400" />} title="Add cache" description="Create or select a cache" />
            )}

            {/* Disk Services */}
            {servicesByType.disk.length > 0 ? (
                servicesByType.disk.map((service) => <ServiceCard key={service.id} service={service} />)
            ) : (
                <EmptyServiceCard
                    icon={<HardDrive className="h-6 w-6 text-gray-400" />}
                    title="Add bucket"
                    description="Store images, videos, and more"
                />
            )}
        </div>
    );
}

// Reusable Components
function SectionHeader({ icon, title }: { icon: React.ReactNode; title: string }) {
    return (
        <div className="flex items-center space-x-2 rounded-lg bg-gray-100/50 px-4 py-2">
            {icon}
            <h2 className="text-base font-medium text-gray-700">{title}</h2>
        </div>
    );
}

const Card = forwardRef<HTMLDivElement, { children: React.ReactNode }>(({ children }, ref) => {
    return (
        <div ref={ref} className="z-10 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            {children}
        </div>
    );
});

function CardHeader({ children, className = '' }: { children: React.ReactNode; className?: string }) {
    return <div className={`border-b border-gray-100 px-4 py-3 font-medium text-gray-700 ${className}`}>{children}</div>;
}

function CardContent({ children }: { children: React.ReactNode }) {
    return <div className="space-y-3 px-4 py-3">{children}</div>;
}

function EdgeNetworkItem({ icon, label, status }: { icon: React.ReactNode; label: string; status: 'active' | 'inactive' }) {
    return (
        <div className="flex items-center justify-between">
            <div className="flex items-center space-x-2">
                {icon}
                <span className="text-sm text-gray-600">{label}</span>
            </div>
            <StatusIndicator status={status} />
        </div>
    );
}

function DomainItem({ domain, isCloudDomain }: { domain: string; isCloudDomain: boolean }) {
    return (
        <div className="space-y-1">
            <div className="flex items-center justify-between">
                <div className="flex items-center space-x-2">
                    <Globe className="h-4 w-4 text-gray-500" />
                    <span className="text-sm text-gray-600">{isCloudDomain ? 'Cloud domain' : 'Custom domain'}</span>
                </div>
                <StatusIndicator status={isCloudDomain ? 'active' : 'inactive'} label={isCloudDomain ? 'Active' : 'Not connected'} />
            </div>
            <div className="truncate pl-6 text-sm text-gray-500">{domain}</div>
        </div>
    );
}

function AppDetailItem({ icon, label, value, isEnabled }: { icon: React.ReactNode; label: string; value: string; isEnabled?: boolean }) {
    return (
        <div className="flex items-center justify-between">
            <div className="flex items-center space-x-2">
                {icon}
                <span className="text-sm text-gray-600">{label}</span>
            </div>
            <div className="flex items-center space-x-2">
                {isEnabled !== undefined && <StatusIndicator status={isEnabled ? 'active' : 'inactive'} />}
                <span className="text-sm font-medium">{value}</span>
            </div>
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

function ServiceCard({ service }: { service: Service }) {
    const getServiceTypeIcon = () => {
        switch (service.category) {
            case 'database':
                return <Database className="h-4 w-4" />;
            case 'cache':
                return <Clock className="h-4 w-4" />;
            case 'disk':
                return <HardDrive className="h-4 w-4" />;
            default:
                return <Box className="h-4 w-4" />;
        }
    };

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
        <Card>
            <CardHeader className="flex items-center justify-between">
                <span className="truncate">{service.name}</span>
                <div className="flex items-center space-x-2">
                    <Badge className={getBadgeColor()}>{getServiceTypeBadge()}</Badge>
                    <Button variant="ghost" size="icon" className="h-8 w-8">
                        <MoreHorizontal className="h-4 w-4" />
                    </Button>
                </div>
            </CardHeader>
            <CardContent>
                <AppDetailItem icon={getServiceTypeIcon()} label="Type" value={service.category} />
                <AppDetailItem icon={<FileText className="h-4 w-4 text-gray-500" />} label="Name" value={service.name} />
                <AppDetailItem
                    icon={<Layers className="h-4 w-4 text-gray-500" />}
                    label="Environment variables"
                    value={`${Object.keys(service.environment_variables).length} variables`}
                />
            </CardContent>
        </Card>
    );
}

function StatusIndicator({ status, label }: { status: 'active' | 'inactive'; label?: string }) {
    return (
        <div className="flex items-center space-x-2">
            <div className={`h-2 w-2 rounded-full ${status === 'active' ? 'bg-green-500' : 'bg-gray-300'}`} />
            {label && <span className="text-sm text-gray-500">{label}</span>}
        </div>
    );
}

function AddButton({ label }: { label: string }) {
    return (
        <Button variant="ghost" className="mt-2 flex items-center pl-0 text-blue-500 hover:bg-transparent hover:text-blue-600" size="sm">
            <PlusCircle className="mr-1 h-4 w-4" />
            {label}
        </Button>
    );
}
