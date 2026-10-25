import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { controlPlane } from '@/routes/updates';

/**
 * "Update now" (docs/08 §11): one sentence of what happens, the servers as
 * an opt-out, and the explicit acknowledgement when there is no off-site
 * backup.
 */
export function UpdateDialog({
    version,
    hasBackupDestination,
    backupDestinationName,
    serverCount,
    disabled,
    label = 'Update now',
}: {
    version: string;
    hasBackupDestination: boolean;
    backupDestinationName: string | null;
    serverCount: number;
    disabled: boolean;
    label?: string;
}) {
    const [open, setOpen] = useState(false);
    const servers = serverCount === 1 ? '1 server' : `${serverCount} servers`;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button disabled={disabled}>{label}</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Update launchroom to {version}?</DialogTitle>
                <DialogDescription>
                    {hasBackupDestination
                        ? `launchroom is backed up${backupDestinationName ? ` to ${backupDestinationName}` : ''} first. If the backup fails, nothing changes.`
                        : 'There is no backup destination, so only a local snapshot is kept for the rollback.'}{' '}
                    The dashboard is unavailable for a few minutes while it
                    restarts, but your apps keep running.
                </DialogDescription>
                <Form
                    {...controlPlane.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="version"
                                value={version}
                            />
                            {serverCount > 0 && (
                                <div className="flex items-start gap-2">
                                    <Checkbox
                                        id="update-servers"
                                        name="updateServers"
                                        value="1"
                                        defaultChecked
                                    />
                                    <Label
                                        htmlFor="update-servers"
                                        className="font-normal"
                                    >
                                        Then update {servers}, one at a time
                                    </Label>
                                </div>
                            )}
                            {!hasBackupDestination && (
                                <div className="flex items-start gap-2">
                                    <Checkbox
                                        id="skip-backup"
                                        name="skipBackup"
                                        value="1"
                                    />
                                    <Label
                                        htmlFor="skip-backup"
                                        className="font-normal"
                                    >
                                        Update without an off-site backup
                                    </Label>
                                </div>
                            )}
                            <InputError
                                message={errors.skipBackup ?? errors.version}
                            />
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="ghost" type="button">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Update to {version}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
