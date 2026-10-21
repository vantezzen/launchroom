import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import services from '@/routes/projects/services';

/**
 * Stages removing a service from one environment (reversible with Discard
 * until deployed; volumes are kept, docs/06 §4.1).
 */
export function RemoveServiceDialog({
    project,
    environment,
    service,
    open,
    onOpenChange,
}: {
    project: string;
    environment: string;
    service: { id: string; name: string; volume: string | null };
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [processing, setProcessing] = useState(false);

    const remove = () => {
        setProcessing(true);
        router.delete(
            services.destroy([project, environment, service.id]).url,
            {
                onSuccess: () => onOpenChange(false),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogTitle>Remove {service.name}?</DialogTitle>
                <DialogDescription>
                    The removal is staged: {service.name} keeps running until
                    you deploy, and you can discard the change before that.
                    {service.volume &&
                        ' Its volume and data are kept after the deploy.'}
                </DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary" type="button">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        disabled={processing}
                        onClick={remove}
                    >
                        Stage removal
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
