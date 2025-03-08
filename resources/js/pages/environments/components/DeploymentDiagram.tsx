import { DotPattern } from '@/components/ui/dot-pattern';
import { TraceLine } from '@/components/ui/trace-line';
import { Environment } from '@/types';
import { useRef } from 'react';
import ApplicationSection from './ApplicationSection';
import NetworkSection from './NetworkSection';
import ServicesSection from './ServicesSection';

export default function DeploymentDiagram({ environment }: { environment: Environment }) {
    const containerRef = useRef<HTMLDivElement>(null);
    const networkRef = useRef<HTMLDivElement>(null);
    const appRef = useRef<HTMLDivElement>(null);
    const servicesRef = useRef<HTMLDivElement[]>([]);

    console.log('DeploymentDiagram', servicesRef);

    return (
        <div className="bg-grid-pattern w-full rounded-lg border bg-zinc-50">
            <div className="relative container mx-auto p-6" ref={containerRef}>
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Network Section */}
                    <NetworkSection domains={environment.domains} networkRef={networkRef} />

                    {/* Application Section */}
                    <ApplicationSection environment={environment} appRef={appRef} />

                    {/* Services Section */}
                    <ServicesSection services={environment.services} servicesRef={servicesRef} />
                </div>
                <TraceLine duration={6} containerRef={containerRef} fromRef={networkRef} toRef={appRef} />

                {servicesRef.current.map((ref, index) => (
                    <TraceLine key={index} duration={6} containerRef={containerRef} fromRef={appRef} toRef={{ current: ref }} />
                ))}

                <DotPattern className="opacity-30" />
            </div>
        </div>
    );
}
