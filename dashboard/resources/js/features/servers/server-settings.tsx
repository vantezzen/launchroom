import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { DeviceImage, DEVICE_LABEL } from '@/components/devices/device-image';
import type { DeviceKind } from '@/components/devices/device-image';
import InputError from '@/components/input-error';
import { UnsavedBar } from '@/components/patterns/unsaved-bar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    LabelList,
    LabelsEditor,
    toLabels,
    toRows,
} from '@/features/scheduling/labels-editor';
import type { LabelRow } from '@/features/scheduling/labels-editor';
import {
    SettingsBody,
    SettingsCard,
    SettingsRow,
} from '@/features/settings/settings-card';
import { update as updateAppearance } from '@/routes/servers/appearance';
import { update as updateSettings } from '@/routes/servers/settings';

type Server = App.Data.Servers.ServerDetailData;

const DEVICES: DeviceKind[] = [
    'cloud',
    'dedicated',
    'vm',
    'minipc',
    'compact',
    'tower',
    'laptop',
    'nas',
    'sbc',
];

function LooksLike({ server }: { server: Server }) {
    const device = server.device;
    const detected = device?.detected ?? 'unknown';
    const value = device?.overridden ? device.kind : 'detected';

    const change = (next: string) =>
        router.patch(
            updateAppearance(server.id).url,
            { device: next === 'detected' ? null : next },
            { preserveScroll: true },
        );

    return (
        <SettingsRow
            label="Looks like"
            description={
                device?.overridden
                    ? `Detected as ${DEVICE_LABEL[detected].toLowerCase()}.`
                    : 'Detected from the server’s hardware.'
            }
            media={
                <DeviceImage
                    device={device?.kind ?? 'unknown'}
                    status="online"
                    size={56}
                />
            }
        >
            {server.canUpdateAppearance ? (
                <Select value={value} onValueChange={change}>
                    <SelectTrigger className="w-64" aria-label="Looks like">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="detected">
                            {DEVICE_LABEL[detected]} (detected)
                        </SelectItem>
                        {DEVICES.filter((kind) => kind !== detected).map(
                            (kind) => (
                                <SelectItem key={kind} value={kind}>
                                    {DEVICE_LABEL[kind]}
                                </SelectItem>
                            ),
                        )}
                    </SelectContent>
                </Select>
            ) : (
                <span className="type-body text-muted-foreground">
                    {DEVICE_LABEL[device?.kind ?? 'unknown']}
                </span>
            )}
        </SettingsRow>
    );
}

function Location({ server }: { server: Server }) {
    const location = server.location;
    const [editing, setEditing] = useState(false);
    const form = useForm({
        label: location?.label ?? '',
        latitude: location ? String(location.latitude) : '',
        longitude: location ? String(location.longitude) : '',
    });

    const save = () => {
        form.transform((data) => ({
            location: {
                label: data.label,
                latitude: Number(data.latitude),
                longitude: Number(data.longitude),
            },
        }));
        form.patch(updateAppearance(server.id).url, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    const forget = () =>
        router.patch(
            updateAppearance(server.id).url,
            { location: null },
            { preserveScroll: true },
        );

    return (
        <>
            <SettingsRow
                label="Location"
                description={
                    location === null
                        ? 'Not set: the server stays off the map.'
                        : location.confirmed
                          ? 'Shown on the servers map.'
                          : 'A guess from the server’s time zone.'
                }
            >
                <span className="type-body">
                    {location?.label ?? (
                        <span className="text-muted-foreground">Unknown</span>
                    )}
                </span>
                {server.canUpdateAppearance && !editing && (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setEditing(true)}
                    >
                        Change
                    </Button>
                )}
            </SettingsRow>
            {editing && (
                <SettingsBody className="flex flex-col gap-4 bg-recessed">
                    <div className="grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
                        <div className="grid gap-1.5">
                            <label
                                htmlFor="location-label"
                                className="type-label"
                            >
                                Place
                            </label>
                            <Input
                                id="location-label"
                                value={form.data.label}
                                placeholder="Berlin, at home"
                                maxLength={64}
                                onChange={(e) =>
                                    form.setData('label', e.target.value)
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <label
                                htmlFor="location-lat"
                                className="type-label"
                            >
                                Latitude
                            </label>
                            <Input
                                id="location-lat"
                                inputMode="decimal"
                                value={form.data.latitude}
                                onChange={(e) =>
                                    form.setData('latitude', e.target.value)
                                }
                                className="type-mono"
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <label
                                htmlFor="location-lon"
                                className="type-label"
                            >
                                Longitude
                            </label>
                            <Input
                                id="location-lon"
                                inputMode="decimal"
                                value={form.data.longitude}
                                onChange={(e) =>
                                    form.setData('longitude', e.target.value)
                                }
                                className="type-mono"
                            />
                        </div>
                    </div>
                    <InputError
                        message={
                            form.errors['location.label' as 'label'] ??
                            form.errors['location.latitude' as 'label'] ??
                            form.errors['location.longitude' as 'label']
                        }
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            size="sm"
                            onClick={save}
                            disabled={form.processing}
                        >
                            Save location
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setEditing(false)}
                        >
                            Cancel
                        </Button>
                        {location !== null && (
                            <Button
                                size="sm"
                                variant="ghost"
                                className="ml-auto"
                                onClick={forget}
                            >
                                Forget location
                            </Button>
                        )}
                    </div>
                </SettingsBody>
            )}
        </>
    );
}

/**
 * Server settings as rows (design-system §1.2): what it looks like, where
 * it is, what may run here. Placement changes collect in the unsaved bar;
 * appearance applies right away.
 */
export function ServerSettings({
    server,
    placement,
}: {
    server: Server;
    placement: App.Data.Servers.ServerPlacementData;
}) {
    const form = useForm<{
        labels: LabelRow[];
        allowPreviews: boolean;
        allowProduction: boolean;
        measureLinks: boolean;
    }>({
        labels: toRows(placement.labels),
        allowPreviews: placement.allowPreviews,
        allowProduction: placement.allowProduction,
        measureLinks: placement.measureLinks,
    });

    const save = () => {
        form.transform((data) => ({
            ...data,
            labels: toLabels(data.labels),
        }));
        form.put(updateSettings(server.id).url, {
            preserveScroll: true,
            onSuccess: () => form.setDefaults(),
        });
    };

    const editable = placement.canUpdate;

    return (
        <>
            <SettingsCard
                id="appearance"
                title="Appearance"
                description="How this server shows up in the fleet."
            >
                <LooksLike server={server} />
                <Location server={server} />
            </SettingsCard>
            <SettingsCard
                id="placement"
                title="Placement"
                description="Changes apply to new deploys."
            >
                <SettingsRow
                    label="Production"
                    description="Off keeps production away from this server, for a test or home box."
                    htmlFor="allow-production"
                >
                    <Switch
                        id="allow-production"
                        checked={form.data.allowProduction}
                        disabled={!editable}
                        onCheckedChange={(checked) =>
                            form.setData('allowProduction', checked)
                        }
                    />
                </SettingsRow>
                <SettingsRow
                    label="Previews"
                    description="Pull request previews only run on servers that allow them."
                    htmlFor="allow-previews"
                >
                    <Switch
                        id="allow-previews"
                        checked={form.data.allowPreviews}
                        disabled={!editable}
                        onCheckedChange={(checked) =>
                            form.setData('allowPreviews', checked)
                        }
                    />
                </SettingsRow>
                <SettingsRow
                    label="Labels"
                    description="Services can require them; replicas spread across zone, then provider."
                    align="start"
                >
                    {editable ? (
                        <div className="w-full sm:w-80">
                            <LabelsEditor
                                id="server-labels"
                                rows={form.data.labels}
                                onChange={(rows) =>
                                    form.setData('labels', rows)
                                }
                            />
                            <InputError message={form.errors.labels} />
                        </div>
                    ) : (
                        <LabelList labels={placement.labels} />
                    )}
                </SettingsRow>
            </SettingsCard>
            <SettingsCard id="network-checks" title="Private network">
                <SettingsRow
                    label="Network checks"
                    description="Measures the private network to your other servers: a round trip every minute, a 16 MB speed test every few hours."
                    htmlFor="measure-links"
                >
                    <Switch
                        id="measure-links"
                        checked={form.data.measureLinks}
                        disabled={!editable}
                        onCheckedChange={(checked) =>
                            form.setData('measureLinks', checked)
                        }
                    />
                </SettingsRow>
            </SettingsCard>
            <UnsavedBar
                open={editable && form.isDirty}
                onReset={() => form.reset()}
                onSave={save}
                saving={form.processing}
            />
        </>
    );
}
