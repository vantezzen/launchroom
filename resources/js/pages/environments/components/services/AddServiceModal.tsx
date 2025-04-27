import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { ScrollArea } from '@/components/ui/scroll-area';
import useRouter from '@/hooks/use-router';
import { cn } from '@/lib/utils';
import { SharedData } from '@/types';
import { AVAILABLE_SERVICES, AvailableService } from '@/types/config';
import { useForm, usePage } from '@inertiajs/react';
import { Clock, Database, Plus } from 'lucide-react';
import { useState } from 'react';

function AddServiceModal() {
    const { data, setData, post, errors, processing, recentlySuccessful } = useForm({
        type: '',
    });
    const [show, setShow] = useState(false);
    const [confirmService, setConfirmService] = useState(false);
    const { route } = useRouter();

    const handleAddService = (type: string) => {
        setData('type', type);
        setConfirmService(true);
    };
    const submit = () => {
        post(route('teams.projects.environments.services.store'));
        setShow(false);
        setConfirmService(false);
    };

    return (
        <Dialog open={show} onOpenChange={setShow}>
            <DialogTrigger className="w-full">
                <Button className="w-full">
                    <Plus />
                    Add Service
                </Button>
            </DialogTrigger>

            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add service</DialogTitle>
                </DialogHeader>

                {confirmService || processing ? (
                    <div className="space-y-6">
                        <p className="text-zinc-500">
                            Are you sure you want to add the service <span className="font-semibold">{data.type}</span>?
                        </p>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing} onClick={submit}>
                                Add service
                            </Button>

                            <Button variant="secondary" disabled={processing} onClick={() => setConfirmService(false)} type="button">
                                Cancel
                            </Button>
                        </div>
                    </div>
                ) : (
                    <ScrollArea className="relative h-[400px] w-full">
                        <SectionTitle icon={<Database className="h-3 w-3 text-gray-500" />} title="Database" />
                        <ServiceSection category="database" allowMultiple={false} onAddService={handleAddService} />
                        <SectionTitle icon={<Clock className="h-3 w-3 text-gray-500" />} title="Cache" />
                        <ServiceSection category="cache" allowMultiple={false} onAddService={handleAddService} />
                    </ScrollArea>
                )}
            </DialogContent>
        </Dialog>
    );
}

function SectionTitle({ icon, title }: { icon: React.ReactNode; title: string }) {
    return (
        <div className="mt-8 mb-3 flex items-center space-x-2">
            {icon}
            <h3 className="font-medium">{title}</h3>
        </div>
    );
}

function ServiceSection({
    category,
    allowMultiple = false,
    onAddService,
}: {
    category: string;
    allowMultiple?: boolean;
    onAddService: (type: string) => void;
}) {
    const {
        props: { currentEnvironment },
    } = usePage<SharedData>();

    const hasExistingService = currentEnvironment?.services.some((service) => service.category === category);
    const allowAdding = !hasExistingService || allowMultiple;

    return (
        <div className={cn('relative grid grid-cols-3 gap-6', { 'pointer-events-none': !allowAdding })}>
            {AVAILABLE_SERVICES.filter((service) => service.category === category).map((service) => (
                <ServiceCard
                    key={service.name}
                    service={service}
                    onSelect={() => {
                        if (allowAdding) {
                            onAddService(service.type);
                        }
                    }}
                    className={cn({ 'opacity-50': !allowAdding })}
                />
            ))}

            {!allowAdding && (
                <div className="absolute inset-0 z-20 flex items-center justify-center rounded-lg bg-white/50 dark:bg-zinc-900/50">
                    <span className="text-zinc-500">You can only add one service of this type</span>
                </div>
            )}
        </div>
    );
}

function ServiceCard({ service, onSelect, className }: { service: AvailableService; onSelect: () => void; className?: string }) {
    return (
        <button
            onClick={(e) => {
                e.stopPropagation();
                e.preventDefault();

                onSelect();
            }}
            className={cn(
                'z-10 flex cursor-pointer flex-col items-center justify-between gap-4 rounded-lg border bg-white p-4 duration-100 hover:bg-zinc-50 dark:bg-zinc-800 dark:hover:bg-zinc-700',
                className,
            )}
        >
            <img src={service.icon} alt={service.name} className="h-6 w-6" />
            <span>{service.name}</span>
        </button>
    );
}

export default AddServiceModal;
