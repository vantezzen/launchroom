import { Head, router } from '@inertiajs/react';
import { Bot } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/patterns/empty-state';
import {
    SettingsBody,
    SettingsCard,
} from '@/components/settings/settings-card';
import { Button } from '@/components/ui/button';
import { requestJson } from '@/lib/http';
import { destroy, rotate, store } from '@/routes/robot-tokens';
import {
    CreateRobotForm,
    RobotTokenDialog,
    RobotTokenSecret,
} from '@/features/robot-tokens/create-robot-dialog';
import type { RobotTokenRequest } from '@/features/robot-tokens/create-robot-dialog';
import { RobotTokenRow } from '@/features/robot-tokens/robot-token-row';
import SettingsLayout from '@/features/settings/settings-layout';

type Props = {
    tokens: App.Data.Api.RobotTokenData[];
    teamName: string;
    canManage: boolean;
    abilities: App.Data.Api.ApiAbilityOptionData[];
    scopes: App.Data.Api.RobotScopeOptionData[];
    lifetimes: number[];
    defaultLifetime: number;
    apiUrl: string;
};

type Shown =
    | { kind: 'create' }
    | {
          kind: 'secret';
          created: App.Data.Api.CreatedRobotTokenData;
          rotated: boolean;
      };

/**
 * Settings → Robot tokens (docs/16 §1.4): team-owned tokens for CI. Every
 * member sees them; owners and admins create, rotate and revoke. The
 * plaintext lives only in the dialog's state.
 */
export default function RobotTokens({
    tokens,
    teamName,
    canManage,
    abilities,
    scopes,
    lifetimes,
    defaultLifetime,
    apiUrl,
}: Props) {
    const [shown, setShown] = useState<Shown | null>(null);
    // The access presets read the abilities the creator's role allows.
    const team: App.Data.Api.ApiTeamOptionData = {
        id: '',
        name: teamName,
        role: 'admin',
        current: true,
        abilities,
    };

    const close = (open: boolean) => {
        if (!open) {
            if (shown?.kind === 'secret') {
                router.reload({ only: ['tokens'] });
            }

            setShown(null);
        }
    };

    const create = async (request: RobotTokenRequest) => {
        const created = await requestJson<App.Data.Api.CreatedRobotTokenData>(
            'post',
            store().url,
            request,
        );
        setShown({ kind: 'secret', created, rotated: false });
    };

    const rotateToken = async (id: string) => {
        const created = await requestJson<App.Data.Api.CreatedRobotTokenData>(
            'post',
            rotate(id).url,
            {},
        );
        setShown({ kind: 'secret', created, rotated: true });
    };

    return (
        <>
            <Head title="Tokens" />

            <SettingsCard
                title="Tokens"
                setting="robots"
                description="For CI and automation. They keep working when someone leaves the team."
                actions={
                    canManage && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setShown({ kind: 'create' })}
                        >
                            Create robot token
                        </Button>
                    )
                }
            >
                {tokens.length === 0 ? (
                    <SettingsBody>
                        <EmptyState icon={Bot} variant="plain">
                            {canManage
                                ? 'No robot tokens yet. Create one for a deploy job, so it doesn’t depend on one person’s token.'
                                : 'No robot tokens yet. Owners and admins create them.'}
                        </EmptyState>
                    </SettingsBody>
                ) : (
                    tokens.map((token) => (
                        <RobotTokenRow
                            key={token.id}
                            token={token}
                            canManage={canManage}
                            onRotate={() => rotateToken(token.id)}
                            onRevoke={() =>
                                new Promise<void>((resolve) =>
                                    router.delete(destroy(token.id).url, {
                                        preserveScroll: true,
                                        onFinish: () => resolve(),
                                    }),
                                )
                            }
                        />
                    ))
                )}
            </SettingsCard>

            {canManage && (
                <RobotTokenDialog open={shown !== null} onOpenChange={close}>
                    {shown?.kind === 'create' && (
                        <CreateRobotForm
                            team={team}
                            scopes={scopes}
                            lifetimes={lifetimes}
                            defaultLifetime={defaultLifetime}
                            onSubmit={create}
                        />
                    )}
                    {shown?.kind === 'secret' && (
                        <RobotTokenSecret
                            created={shown.created}
                            rotated={shown.rotated}
                            apiUrl={apiUrl}
                        />
                    )}
                </RobotTokenDialog>
            )}
        </>
    );
}

RobotTokens.layout = SettingsLayout;
