import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import { Switch } from '@/components/ui/switch';
import { SettingsCard, SettingsRow } from '@/features/settings/settings-card';
import { settings as backupSettings } from '@/routes/backups';
import { preferences as savePreferences } from '@/routes/updates';

type Channel = 'stable' | 'beta';

const hour = (h: number): string =>
    h === 0
        ? 'midnight'
        : h === 12
          ? 'noon'
          : h < 12
            ? `${h} am`
            : `${h - 12} pm`;

/**
 * "How launchroom updates" (docs/11 §4): the channel and opt-in automatic
 * updates. Both apply on change (their own action, design-system §13.9);
 * the server asks for the password first when it wasn't confirmed lately.
 */
export function UpdatePreferences({
    preferences,
}: {
    preferences: App.Data.Updates.UpdatePreferencesData;
}) {
    const [error, setError] = useState<string | null>(null);
    const channel: Channel = preferences.channel === 'beta' ? 'beta' : 'stable';

    const save = (next: { channel: Channel; autoUpdate: boolean }) =>
        router.put(savePreferences().url, next, {
            preserveScroll: true,
            onError: (errors) =>
                setError(errors.autoUpdate ?? errors.channel ?? null),
            onSuccess: () => setError(null),
        });

    const zone =
        preferences.timezone === 'UTC'
            ? 'UTC'
            : preferences.timezone.replace(/_/g, ' ');

    return (
        <SettingsCard
            title="How launchroom updates"
            footer="Your apps keep running during every update. Servers follow the dashboard, never the other way round."
        >
            <SettingsRow
                label="Channel"
                description={
                    channel === 'beta'
                        ? 'New releases a little earlier, with rough edges.'
                        : 'Releases once they are ready. Recommended.'
                }
            >
                <TimeRangeSelect<Channel>
                    label="Update channel"
                    value={channel}
                    options={[
                        { value: 'stable', label: 'Stable' },
                        { value: 'beta', label: 'Beta' },
                    ]}
                    onChange={(value) =>
                        value !== channel &&
                        save({
                            channel: value,
                            autoUpdate: preferences.autoUpdate,
                        })
                    }
                />
            </SettingsRow>
            <SettingsRow
                label="Install updates automatically"
                htmlFor="auto-update"
                inline
                description={
                    preferences.canAutoUpdate || preferences.autoUpdate ? (
                        `At night (${hour(preferences.windowStart)}–${hour(preferences.windowEnd)}, ${zone}), after a backup, then your servers. A release that fails is left for you.`
                    ) : (
                        <>
                            Needs a backup of launchroom first.{' '}
                            <Link
                                href={backupSettings()}
                                className="text-signal underline-offset-4 hover:underline"
                            >
                                Set one up
                            </Link>
                        </>
                    )
                }
            >
                <Switch
                    id="auto-update"
                    checked={preferences.autoUpdate}
                    disabled={
                        !preferences.canAutoUpdate && !preferences.autoUpdate
                    }
                    onCheckedChange={(autoUpdate) =>
                        save({ channel, autoUpdate })
                    }
                />
            </SettingsRow>
            {error !== null && (
                <div className="px-5 pb-4">
                    <InputError message={error} />
                </div>
            )}
        </SettingsCard>
    );
}
