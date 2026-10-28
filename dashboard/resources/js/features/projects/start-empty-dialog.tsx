import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store } from '@/routes/projects';

/**
 * "Start with a blank canvas": a project with its environments and nothing
 * else, named here, opened on its canvas (docs/08 §13).
 */
export function StartEmptyDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogTitle>Start with a blank canvas</DialogTitle>
                <DialogDescription>
                    Add repositories, images and databases on its canvas
                    afterwards.
                </DialogDescription>
                <Form {...store.form()} className="flex flex-col gap-6">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="project-name">
                                    Project name
                                </Label>
                                <Input
                                    id="project-name"
                                    name="name"
                                    required
                                    maxLength={100}
                                    autoComplete="off"
                                    autoFocus
                                    placeholder="shop"
                                />
                                <InputError message={errors.name} />
                            </div>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="ghost" type="button">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    Create project
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
