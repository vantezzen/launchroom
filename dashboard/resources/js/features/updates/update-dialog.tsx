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
import { OptionCards } from '@/components/patterns/option-cards';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { backupChoices, backupFields } from '@/features/updates/backup-choice';
import type { BackupChoiceValue } from '@/features/updates/backup-choice';
import { controlPlane } from '@/routes/updates';

/**
 * "Update now" (docs/08 §11, docs/11 §4.1): one sentence of what happens,
 * which backup comes first (the latest one, the one running now, a new
 * one, or none), the servers as an opt-out, and the explicit
 * acknowledgement when there is no off-site backup.
 */
export function UpdateDialog({
    version,
    hasBackupDestination,
    backupDestinationName,
    latestBackup,
    runningBackup,
    serverCount,
    disabled,
    label = 'Update now',
    retryOf,
}: {
    version: string;
    hasBackupDestination: boolean;
    backupDestinationName: string | null;
    latestBackup: App.Data.Updates.PreUpgradeBackupData | null;
    runningBackup: App.Data.Updates.PreUpgradeBackupData | null;
    serverCount: number;
    disabled: boolean;
    label?: string;
    /** "Try again": the update whose pinned release is installed again. */
    retryOf?: string;
}) {
    const [open, setOpen] = useState(false);
    const servers = serverCount === 1 ? '1 server' : `${serverCount} servers`;
    const { choices, preselected } = backupChoices(
        latestBackup,
        runningBackup,
        backupDestinationName,
    );
    const [picked, setPicked] = useState<BackupChoiceValue | null>(null);
    const choice =
        picked !== null && choices.some((option) => option.value === picked)
            ? picked
            : preselected;
    const fields = backupFields(choice, latestBackup, runningBackup);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setPicked(null);
            }}
        >
            <DialogTrigger asChild>
                <Button disabled={disabled}>{label}</Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-xl">
                <DialogTitle>Update launchroom to {version}?</DialogTitle>
                <DialogDescription>
                    {hasBackupDestination
                        ? ''
                        : 'There is no backup destination, so only a local snapshot is kept for the rollback. '}
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
                            {retryOf !== undefined && (
                                <input
                                    type="hidden"
                                    name="retryOf"
                                    value={retryOf}
                                />
                            )}
                            {hasBackupDestination && (
                                <OptionCards
                                    label="Backup before the update"
                                    options={choices}
                                    value={choice}
                                    onChange={setPicked}
                                />
                            )}
                            {fields.backupRunId !== undefined && (
                                <input
                                    type="hidden"
                                    name="backupRunId"
                                    value={fields.backupRunId}
                                />
                            )}
                            {hasBackupDestination &&
                                fields.skipBackup !== undefined && (
                                    <input
                                        type="hidden"
                                        name="skipBackup"
                                        value={fields.skipBackup}
                                    />
                                )}
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
                                message={
                                    errors.backupRunId ??
                                    errors.skipBackup ??
                                    errors.retryOf ??
                                    errors.version
                                }
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
