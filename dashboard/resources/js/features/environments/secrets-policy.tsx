import { router } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Switch } from '@/components/ui/switch';
import { SettingsCard, SettingsRow } from '@/features/settings/settings-card';
import { secretsPolicy } from '@/routes/environments';

/**
 * "Production secrets must differ from preview" (docs/06 §3.1): a project
 * setting, forced on by the team setting. A settings row with a switch that
 * applies immediately.
 */
export function SecretsPolicy({
    project,
    policy,
    canManage,
}: {
    project: string;
    policy: { project: boolean; team: boolean };
    canManage: boolean;
}) {
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const enforced = policy.team;

    return (
        <SettingsCard title="Secrets" setting="secrets">
            <SettingsRow
                label="Production secrets must differ from preview"
                description={
                    <>
                        Saving, deploying and promoting fail when a production
                        secret has the same value as in a preview.
                        {enforced &&
                            ' Your team turns this on for every project.'}
                        <InputError message={error ?? undefined} />
                    </>
                }
                htmlFor="secrets-must-differ"
                inline
            >
                <Switch
                    id="secrets-must-differ"
                    checked={policy.project || enforced}
                    disabled={!canManage || enforced || processing}
                    onCheckedChange={(checked) => {
                        setProcessing(true);
                        setError(null);
                        router.put(
                            secretsPolicy(project).url,
                            { productionSecretsMustDiffer: checked },
                            {
                                preserveScroll: true,
                                onError: (errors) =>
                                    setError(
                                        Object.values(errors)[0] ??
                                            'The policy could not be saved.',
                                    ),
                                onFinish: () => setProcessing(false),
                            },
                        );
                    }}
                />
            </SettingsRow>
        </SettingsCard>
    );
}
