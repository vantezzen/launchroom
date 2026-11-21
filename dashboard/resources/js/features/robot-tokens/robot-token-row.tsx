import { Boxes, FolderGit2, TriangleAlert } from 'lucide-react';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { MetaLine } from '@/components/patterns/meta-line';
import { SettingsRow } from '@/components/settings/settings-card';
import { TimeAgo } from '@/components/time-ago';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { abilitySummary } from '@/features/api-tokens/ability-presets';
import { vocabulary } from '@/lib/vocabulary';
import { expiryLine } from './expiry';

type Token = App.Data.Api.RobotTokenData;

/** "All projects", "shop" or "shop › api": what a robot token reaches. */
export function scopeLabel(token: Token): string {
    if (token.projectName === null) {
        return 'All projects';
    }

    return token.serviceName === null
        ? token.projectName
        : `${token.projectName} › ${token.serviceName}`;
}

/** The scope chip: an icon says project or service, the text names it. */
export function ScopeChip({ token }: { token: Token }) {
    const Icon = token.serviceName !== null ? Boxes : FolderGit2;

    return (
        <Badge variant="outline" className="gap-1 font-normal">
            {token.projectName !== null && (
                <Icon className="size-3" aria-hidden="true" />
            )}
            {scopeLabel(token)}
        </Badge>
    );
}

function AccessChip({ token }: { token: Token }) {
    const summary = abilitySummary(token);
    const chip = (
        <Badge variant="secondary" tabIndex={summary.custom ? 0 : undefined}>
            {summary.label}
        </Badge>
    );

    if (!summary.custom) {
        return chip;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>{chip}</TooltipTrigger>
            <TooltipContent>
                {token.abilities
                    .map((ability) => vocabulary.ApiAbility[ability].label)
                    .join(', ')}
            </TooltipContent>
        </Tooltip>
    );
}

/**
 * One robot token in Settings → Robot tokens (docs/16 §1.4): name, hint,
 * access and scope, who created it, last use and expiry; Rotate and Revoke
 * for owners and admins.
 */
export function RobotTokenRow({
    token,
    canManage,
    onRotate,
    onRevoke,
}: {
    token: Token;
    canManage: boolean;
    onRotate: () => Promise<void>;
    onRevoke: () => Promise<void>;
}) {
    const expiry = expiryLine(token.expiresAt);

    return (
        <SettingsRow
            align="start"
            label={
                <span className="flex flex-wrap items-center gap-2">
                    {token.name}
                    {token.hint !== null && (
                        <span className="type-mono-small font-normal text-muted-foreground">
                            {token.hint}
                        </span>
                    )}
                    <AccessChip token={token} />
                    <ScopeChip token={token} />
                </span>
            }
            description={
                <MetaLine>
                    {token.createdBy
                        ? `Created by ${token.createdBy}`
                        : 'Creator no longer in launchroom'}
                    {token.lastUsedAt ? (
                        <span>
                            Last used <TimeAgo value={token.lastUsedAt} />
                            {token.lastUsedIp && (
                                <>
                                    {' '}
                                    from{' '}
                                    <span className="type-mono-small">
                                        {token.lastUsedIp}
                                    </span>
                                </>
                            )}
                        </span>
                    ) : (
                        'Never used'
                    )}
                    <span
                        className={
                            expiry.soon ? 'text-status-progress' : undefined
                        }
                    >
                        {expiry.soon && (
                            <TriangleAlert
                                className="mr-1 inline size-3.5 align-[-2px]"
                                aria-hidden="true"
                            />
                        )}
                        {expiry.text}
                    </span>
                </MetaLine>
            }
        >
            {canManage && (
                <div className="flex flex-wrap gap-2">
                    <ConfirmPopover
                        title={`Rotate ${token.name}?`}
                        consequence="The current token stops working right away. Put the new one into your CI secrets."
                        confirmLabel="Rotate"
                        onConfirm={onRotate}
                    >
                        <Button variant="outline" size="sm">
                            Rotate
                        </Button>
                    </ConfirmPopover>
                    <ConfirmPopover
                        title={`Revoke ${token.name}?`}
                        consequence="Jobs using it stop working right away."
                        confirmLabel="Revoke"
                        tone="destructive"
                        onConfirm={onRevoke}
                    >
                        <Button variant="destructive" size="sm">
                            Revoke
                        </Button>
                    </ConfirmPopover>
                </div>
            )}
        </SettingsRow>
    );
}
