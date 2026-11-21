import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import { CopyValue } from '@/components/copy-value';
import InputError from '@/components/input-error';
import { ChoiceTiles } from '@/components/patterns/choice-tiles';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    AbilityFields,
    defaultAbilities,
    lifetimeEnd,
    lifetimeLabel,
} from '@/features/api-tokens/ability-fields';

type Scope = App.Data.Api.RobotScopeOptionData;
type TeamOption = App.Data.Api.ApiTeamOptionData;
type Reach = 'all' | 'project' | 'service';

export type RobotTokenRequest = {
    name: string;
    expiresInDays: number;
    abilities: App.Enums.ApiAbility[];
    fullAccess: boolean;
    project: string | null;
    service: string | null;
};

const REACH_SENTENCE: Record<Reach, string> = {
    all: 'Every project of the team, now and later.',
    project: 'One project with all its services and environments.',
    service: 'One service: its deployments, logs, variables and commands.',
};

/**
 * Settings → Robot tokens → Create (docs/16 §1.4): name, what it reaches
 * (all projects, one project or one service), expiry and access. The
 * plaintext appears in {@see RobotTokenSecret} once it's created.
 */
export function CreateRobotForm({
    team,
    scopes,
    lifetimes,
    defaultLifetime,
    onSubmit,
}: {
    team: TeamOption;
    scopes: Scope[];
    lifetimes: number[];
    defaultLifetime: number;
    onSubmit: (request: RobotTokenRequest) => Promise<void>;
}) {
    const [name, setName] = useState('');
    const [reach, setReach] = useState<Reach>('all');
    const [project, setProject] = useState<string | null>(
        scopes[0]?.id ?? null,
    );
    const [service, setService] = useState<string | null>(null);
    const [days, setDays] = useState(String(defaultLifetime));
    const [abilities, setAbilities] = useState<App.Enums.ApiAbility[]>(
        defaultAbilities(team),
    );
    const [fullAccess, setFullAccess] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const services =
        scopes.find((entry) => entry.id === project)?.services ?? [];

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            await onSubmit({
                name: name.trim() === '' ? 'Robot' : name.trim(),
                expiresInDays: Number(days),
                abilities,
                fullAccess,
                project: reach === 'all' ? null : project,
                service: reach === 'service' ? service : null,
            });
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'Could not create the token.',
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            <div className="flex flex-col gap-1.5">
                <DialogTitle>Create robot token</DialogTitle>
                <DialogDescription>
                    It belongs to {team.name}, not to you, and keeps working
                    when someone leaves the team.
                </DialogDescription>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="robot-name">Name</Label>
                <Input
                    id="robot-name"
                    value={name}
                    maxLength={80}
                    placeholder="GitHub Actions"
                    autoFocus
                    onChange={(event) => setName(event.target.value)}
                />
            </div>

            <fieldset className="flex flex-col gap-3">
                <legend className="mb-2 type-label">Reaches</legend>
                <TimeRangeSelect<Reach>
                    label="Reaches"
                    value={reach}
                    onChange={setReach}
                    options={[
                        { value: 'all', label: 'All projects' },
                        { value: 'project', label: 'One project' },
                        { value: 'service', label: 'One service' },
                    ]}
                    className="self-start"
                />
                <p className="type-small text-muted-foreground">
                    {REACH_SENTENCE[reach]}
                </p>
                {reach !== 'all' && (
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <Select
                            value={project ?? undefined}
                            onValueChange={(value) => {
                                setProject(value);
                                setService(null);
                            }}
                        >
                            <SelectTrigger
                                aria-label="Project"
                                className="w-full sm:flex-1"
                            >
                                <SelectValue placeholder="Pick a project" />
                            </SelectTrigger>
                            <SelectContent>
                                {scopes.map((entry) => (
                                    <SelectItem key={entry.id} value={entry.id}>
                                        {entry.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {reach === 'service' && (
                            <Select
                                value={service ?? undefined}
                                onValueChange={setService}
                                disabled={services.length === 0}
                            >
                                <SelectTrigger
                                    aria-label="Service"
                                    className="w-full sm:flex-1"
                                >
                                    <SelectValue
                                        placeholder={
                                            services.length === 0
                                                ? 'No services yet'
                                                : 'Pick a service'
                                        }
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    {services.map((entry) => (
                                        <SelectItem
                                            key={entry.id}
                                            value={entry.id}
                                        >
                                            {entry.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                    </div>
                )}
            </fieldset>

            <div className="grid gap-2">
                <span className="type-label">Expires after</span>
                <ChoiceTiles
                    label="Expires after"
                    columns={4}
                    value={days}
                    onChange={setDays}
                    options={lifetimes.map((value) => ({
                        value: String(value),
                        label: lifetimeLabel(value),
                        description: lifetimeEnd(value),
                    }))}
                    className="[&_label]:min-h-14"
                />
            </div>

            <AbilityFields
                legend="Access"
                idPrefix="robot-ability"
                team={team}
                value={abilities}
                onChange={setAbilities}
                fullAccess={fullAccess}
                onFullAccessChange={setFullAccess}
                fullAccessHint="Everything a robot token can do, including permissions launchroom adds later."
                explainUnavailable
            />

            <InputError message={error ?? undefined} />

            <DialogFooter>
                <DialogClose asChild>
                    <Button type="button" variant="ghost">
                        Cancel
                    </Button>
                </DialogClose>
                <Button
                    type="submit"
                    disabled={
                        busy ||
                        (reach !== 'all' && project === null) ||
                        (reach === 'service' && service === null)
                    }
                >
                    {busy && <Spinner />}
                    Create token
                </Button>
            </DialogFooter>
        </form>
    );
}

/** The show-once view after Create or Rotate. */
export function RobotTokenSecret({
    created,
    rotated,
    apiUrl,
}: {
    created: App.Data.Api.CreatedRobotTokenData;
    rotated: boolean;
    apiUrl: string;
}) {
    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-col gap-1.5">
                <DialogTitle>
                    {rotated
                        ? `New token for ${created.token.name}`
                        : 'Copy the robot token'}
                </DialogTitle>
                <DialogDescription>
                    It's shown only once. Store it as the CI secret{' '}
                    <code className="type-mono-small">LAUNCHROOM_TOKEN</code>
                    {rotated ? ', in place of the old one.' : '.'}
                </DialogDescription>
            </div>
            <CopyValue value={created.plainText} label="token" />
            <div className="flex flex-col gap-2">
                <p className="type-small text-muted-foreground">Try it</p>
                <CopyValue
                    value={`curl -H "Authorization: Bearer $LAUNCHROOM_TOKEN" ${apiUrl}/me`}
                    label="example request"
                />
            </div>
            <DialogFooter>
                <DialogClose asChild>
                    <Button>Done</Button>
                </DialogClose>
            </DialogFooter>
        </div>
    );
}

export function RobotTokenDialog({
    open,
    onOpenChange,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    children: ReactNode;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">{children}</DialogContent>
        </Dialog>
    );
}
