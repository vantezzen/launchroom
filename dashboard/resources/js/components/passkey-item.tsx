import { KeyRound, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { Passkey } from '@/types/auth';

type Props = {
    passkey: Passkey;
    onDelete: (id: number, onError: () => void) => void;
};

export default function PasskeyItem({ passkey, onDelete }: Props) {
    const [isDeleting, setIsDeleting] = useState(false);

    const handleDelete = () => {
        setIsDeleting(true);
        onDelete(passkey.id, () => setIsDeleting(false));
    };

    return (
        <div className="flex items-center justify-between gap-4 px-5 py-4">
            <div className="flex items-center gap-4">
                <div className="flex size-9 shrink-0 items-center justify-center rounded-md border bg-card">
                    <KeyRound className="size-4 text-muted-foreground" />
                </div>
                <div className="flex flex-col gap-0.5">
                    <div className="flex items-center gap-2.5">
                        <p className="type-body font-medium">{passkey.name}</p>
                        {passkey.other_address && (
                            <span className="inline-flex h-5 items-center rounded-full border px-2 type-small text-muted-foreground">
                                For {passkey.other_address}
                            </span>
                        )}
                        {passkey.authenticator && (
                            <span className="inline-flex h-5 items-center rounded-full bg-hover px-2 type-small text-muted-foreground">
                                {passkey.authenticator}
                            </span>
                        )}
                    </div>
                    {passkey.other_address && (
                        <p className="type-small text-muted-foreground">
                            Doesn’t work at this address. Add a new passkey,
                            then remove this one.
                        </p>
                    )}
                    <p className="type-small text-muted-foreground">
                        Added {passkey.created_at_diff}
                        {passkey.last_used_at_diff && (
                            <>
                                <span className="mx-1 text-muted-foreground/50">
                                    /
                                </span>
                                Last used {passkey.last_used_at_diff}
                            </>
                        )}
                    </p>
                </div>
            </div>

            <Dialog>
                <DialogTrigger asChild>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-muted-foreground"
                    >
                        <Trash2 className="h-4 w-4" />
                        <span className="sr-only">Remove</span>
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogTitle>Remove passkey</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to remove the "{passkey.name}"
                        passkey? You will no longer be able to use it to sign
                        in.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={handleDelete}
                            disabled={isDeleting}
                        >
                            {isDeleting ? 'Removing...' : 'Remove passkey'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
