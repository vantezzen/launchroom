import { Head, router, useForm } from '@inertiajs/react';
import { MoreHorizontal, UserPlus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { ChoiceTiles } from '@/components/patterns/choice-tiles';
import { EmptyState } from '@/components/patterns/empty-state';
import { SidePanel, SheetSection } from '@/components/patterns/sheet';
import { TimeAgo } from '@/components/time-ago';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { SettingsCard, SettingsRow } from '@/features/settings/settings-card';
import { useInitials } from '@/hooks/use-initials';
import { consoleRecording as consoleRecordingRoute, edit } from '@/routes/team';
import {
    destroy as destroyInvitation,
    store as storeInvitation,
} from '@/routes/team/invitations';
import {
    destroy as destroyMember,
    permissions as updatePermissions,
    update as updateMember,
} from '@/routes/team/members';

type Props = {
    team: App.Data.Teams.TeamData;
    members: App.Data.Teams.MemberData[];
    invitations: App.Data.Teams.InvitationData[];
    canManageMembers: boolean;
    assignableRoles: App.Enums.TeamRole[];
    /** Opt-in console transcripts (docs/03 §11.5). */
    consoleRecording?: { enabled: boolean; canChange: boolean };
};

/** Console & Data access a developer can be given (docs/09 R33). */
const accessLabel: Partial<Record<App.Enums.Permission, string>> = {
    'containers.exec': 'Console',
    'databases.query': 'Data',
};

function setGrant(
    member: App.Data.Teams.MemberData,
    permission: App.Enums.Permission,
    on: boolean,
) {
    const next = on
        ? [...new Set([...member.grants, permission])]
        : member.grants.filter((grant) => grant !== permission);

    router.put(
        updatePermissions(member.id).url,
        { permissions: next },
        { preserveScroll: true },
    );
}

function AccessChips({ member }: { member: App.Data.Teams.MemberData }) {
    const labels = member.grants
        .map((grant) => accessLabel[grant])
        .filter((label): label is string => Boolean(label));

    if (member.role !== 'developer' || labels.length === 0) {
        return null;
    }

    return (
        <span className="hidden type-small text-muted-foreground sm:inline">
            + {labels.join(' & ')}
        </span>
    );
}

const roleLabel = (role: App.Enums.TeamRole): string =>
    role.charAt(0).toUpperCase() + role.slice(1);

/** What each role can do, in one line (docs/09 §2 permissions). */
const roleSummary: Record<App.Enums.TeamRole, string> = {
    owner: 'Everything, including the team',
    admin: 'Servers, members, backups, projects',
    developer: 'Deploys and edits projects',
    viewer: 'Sees everything, changes nothing',
};

function RoleChip({ role }: { role: App.Enums.TeamRole }) {
    return (
        <span className="inline-flex h-6 items-center rounded-full bg-hover px-2.5 type-small text-foreground">
            {roleLabel(role)}
        </span>
    );
}

function MemberAvatar({ name }: { name: string }) {
    const getInitials = useInitials();

    return (
        <Avatar className="size-9">
            <AvatarFallback className="bg-hover type-label text-muted-foreground">
                {getInitials(name)}
            </AvatarFallback>
        </Avatar>
    );
}

/**
 * The `⋯` menu of a member: role (when it can change) and remove/leave,
 * confirmed in a popover anchored to the menu button (design-system §6:
 * no destructive buttons in lists).
 */
function MemberMenu({
    member,
    teamName,
    assignableRoles,
}: {
    member: App.Data.Teams.MemberData;
    teamName: string;
    assignableRoles: App.Enums.TeamRole[];
}) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const leaving = member.isCurrentUser;

    const grantable = member.grantable.filter(
        (permission) => accessLabel[permission],
    );

    if (!member.canEdit && !member.canRemove && grantable.length === 0) {
        return <span className="w-8" aria-hidden="true" />;
    }

    const remove = () => {
        setBusy(true);
        router.delete(destroyMember(member.id).url, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setConfirming(false);
            },
        });
    };

    return (
        <Popover open={confirming} onOpenChange={setConfirming}>
            <DropdownMenu modal={false}>
                <PopoverAnchor asChild>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label={`Options for ${member.name}`}
                        >
                            <MoreHorizontal aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                </PopoverAnchor>
                <DropdownMenuContent align="end" className="w-56">
                    {member.canEdit && (
                        <>
                            <DropdownMenuLabel className="type-small text-muted-foreground">
                                Role
                            </DropdownMenuLabel>
                            <DropdownMenuRadioGroup
                                value={member.role}
                                onValueChange={(role) =>
                                    router.patch(
                                        updateMember(member.id).url,
                                        { role },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {assignableRoles.map((role) => (
                                    <DropdownMenuRadioItem
                                        key={role}
                                        value={role}
                                    >
                                        {roleLabel(role)}
                                    </DropdownMenuRadioItem>
                                ))}
                            </DropdownMenuRadioGroup>
                        </>
                    )}
                    {grantable.length > 0 && (
                        <>
                            {member.canEdit && <DropdownMenuSeparator />}
                            <DropdownMenuLabel className="type-small text-muted-foreground">
                                Access
                            </DropdownMenuLabel>
                            {grantable.map((permission) => (
                                <DropdownMenuCheckboxItem
                                    key={permission}
                                    checked={member.grants.includes(permission)}
                                    onCheckedChange={(on) =>
                                        setGrant(
                                            member,
                                            permission,
                                            on === true,
                                        )
                                    }
                                >
                                    {accessLabel[permission]}
                                </DropdownMenuCheckboxItem>
                            ))}
                        </>
                    )}
                    {(member.canEdit || grantable.length > 0) &&
                        member.canRemove && <DropdownMenuSeparator />}
                    {member.canRemove && (
                        <DropdownMenuItem
                            className="text-status-error focus:text-status-error"
                            onSelect={() => setConfirming(true)}
                        >
                            {leaving ? 'Leave team…' : 'Remove from team…'}
                        </DropdownMenuItem>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
            <PopoverContent align="end" className="flex w-80 flex-col gap-3">
                <div className="flex flex-col gap-1">
                    <p className="type-label">
                        {leaving
                            ? `Leave ${teamName}?`
                            : `Remove ${member.name}?`}
                    </p>
                    <p className="type-body text-muted-foreground">
                        {leaving
                            ? 'You lose access to this team’s servers and projects.'
                            : `${member.name} loses access to this team’s servers and projects.`}
                    </p>
                </div>
                <div className="flex justify-end gap-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setConfirming(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        size="sm"
                        variant="destructive"
                        disabled={busy}
                        onClick={remove}
                    >
                        {leaving ? 'Leave team' : 'Remove member'}
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}

function InvitePanel({
    open,
    onOpenChange,
    assignableRoles,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    assignableRoles: App.Enums.TeamRole[];
}) {
    const form = useForm<{ emails: string; role: App.Enums.TeamRole }>({
        emails: '',
        role: assignableRoles.includes('developer')
            ? 'developer'
            : assignableRoles[0],
    });
    const count = form.data.emails
        .split(/[\s,;]+/)
        .filter((part) => part.includes('@')).length;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(storeInvitation().url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('emails');
                onOpenChange(false);
            },
        });
    };

    return (
        <SidePanel
            open={open}
            onOpenChange={onOpenChange}
            title="Invite people"
            description="They get an email with a link that works once, for 7 days."
            size="sm"
            footer={
                <div className="flex justify-end gap-2">
                    <Button
                        variant="ghost"
                        type="button"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        form="invite-form"
                        disabled={form.processing || count === 0}
                    >
                        {form.processing && <Spinner />}
                        {count > 1
                            ? `Send ${count} invitations`
                            : 'Send invitation'}
                    </Button>
                </div>
            }
        >
            <form id="invite-form" onSubmit={submit} className="contents">
                <SheetSection>
                    <div className="grid gap-2">
                        <Label htmlFor="invite-emails">Email addresses</Label>
                        <Textarea
                            id="invite-emails"
                            value={form.data.emails}
                            onChange={(event) =>
                                form.setData('emails', event.target.value)
                            }
                            rows={4}
                            autoComplete="off"
                            spellCheck={false}
                            placeholder="mara@acme.com, jonas@acme.com"
                            autoFocus
                        />
                        <p className="type-small text-muted-foreground">
                            Separate several with commas or new lines.
                        </p>
                        <InputError message={form.errors.emails} />
                    </div>
                </SheetSection>
                <SheetSection title="Role">
                    <ChoiceTiles
                        label="Role"
                        columns={2}
                        value={form.data.role}
                        onChange={(role) => form.setData('role', role)}
                        options={assignableRoles.map((role) => ({
                            value: role,
                            label: roleLabel(role),
                            description: roleSummary[role],
                        }))}
                    />
                    <InputError message={form.errors.role} />
                </SheetSection>
            </form>
        </SidePanel>
    );
}

function InvitationRow({
    invitation,
}: {
    invitation: App.Data.Teams.InvitationData;
}) {
    const resend = () =>
        router.post(
            storeInvitation().url,
            { email: invitation.email, role: invitation.role },
            { preserveScroll: true },
        );
    const revoke = () =>
        router.delete(destroyInvitation(invitation.id).url, {
            preserveScroll: true,
        });

    return (
        <SettingsRow
            media={<MemberAvatar name={invitation.email} />}
            inline
            label={invitation.email}
            description={
                invitation.expired ? (
                    'Expired'
                ) : (
                    <>
                        Expires <TimeAgo value={invitation.expiresAt} />
                        {invitation.invitedBy !== null &&
                            ` · invited by ${invitation.invitedBy}`}
                    </>
                )
            }
        >
            <RoleChip role={invitation.role} />
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label={`Options for the invitation to ${invitation.email}`}
                    >
                        <MoreHorizontal aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem onSelect={resend}>
                        Send again
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={revoke}>
                        Revoke invitation
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SettingsRow>
    );
}

/**
 * Settings → Members (docs/design/flows.md §16): people as rows with their
 * role, changes behind `⋯`, invitations in a sheet.
 */
export default function TeamSettings({
    team,
    members,
    invitations,
    canManageMembers,
    assignableRoles,
    consoleRecording,
}: Props) {
    const [inviting, setInviting] = useState(false);
    const count = members.length;

    return (
        <>
            <Head title="Members" />

            <SettingsCard
                title={team.name}
                description={`${count} ${count === 1 ? 'member' : 'members'}`}
                actions={
                    canManageMembers && (
                        <Button onClick={() => setInviting(true)}>
                            <UserPlus aria-hidden="true" />
                            Invite people
                        </Button>
                    )
                }
            >
                {members.map((member) => (
                    <SettingsRow
                        key={member.id}
                        media={<MemberAvatar name={member.name} />}
                        label={
                            <span className="inline-flex items-center gap-2">
                                {member.name}
                                {member.isCurrentUser && (
                                    <span className="type-small font-normal text-muted-foreground">
                                        You
                                    </span>
                                )}
                            </span>
                        }
                        description={member.email}
                        className="py-3.5"
                        inline
                    >
                        <AccessChips member={member} />
                        <RoleChip role={member.role} />
                        <MemberMenu
                            member={member}
                            teamName={team.name}
                            assignableRoles={assignableRoles}
                        />
                    </SettingsRow>
                ))}
            </SettingsCard>

            {canManageMembers &&
                (invitations.length > 0 ? (
                    <SettingsCard
                        title="Invited"
                        description="Waiting for them to accept"
                    >
                        {invitations.map((invitation) => (
                            <InvitationRow
                                key={invitation.id}
                                invitation={invitation}
                            />
                        ))}
                    </SettingsCard>
                ) : (
                    count === 1 && (
                        <EmptyState
                            icon={UserPlus}
                            action={
                                <Button
                                    variant="outline"
                                    onClick={() => setInviting(true)}
                                >
                                    Invite people
                                </Button>
                            }
                        >
                            Your teammates will show up here once they join.
                        </EmptyState>
                    )
                ))}

            {consoleRecording &&
                (consoleRecording.canChange || consoleRecording.enabled) && (
                    <SettingsCard
                        title="Console recording"
                        description="Keep a transcript of what console sessions showed."
                        footer="Output only, never keystrokes. Secret values are masked; reading a recording needs a fresh password and is audited."
                    >
                        <SettingsRow
                            label="Record console sessions"
                            description="Applies to sessions opened from now on."
                            htmlFor="record-console"
                            inline
                        >
                            <Switch
                                id="record-console"
                                checked={consoleRecording.enabled}
                                disabled={!consoleRecording.canChange}
                                onCheckedChange={(enabled) =>
                                    router.put(
                                        consoleRecordingRoute().url,
                                        { enabled },
                                        { preserveScroll: true },
                                    )
                                }
                            />
                        </SettingsRow>
                    </SettingsCard>
                )}

            {canManageMembers && (
                <InvitePanel
                    open={inviting}
                    onOpenChange={setInviting}
                    assignableRoles={assignableRoles}
                />
            )}
        </>
    );
}

TeamSettings.layout = {
    breadcrumbs: [
        { title: 'Settings', href: edit() },
        {
            title: 'Members',
            href: edit(),
        },
    ],
};
