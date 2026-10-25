import { Head, Link, router } from '@inertiajs/react';
import { EmptyState } from '@/components/patterns/empty-state';
import { MetaLine } from '@/components/patterns/meta-line';
import { Page, PageHeader } from '@/components/patterns/page';
import { TimeAgo } from '@/components/time-ago';
import { ArrowLeftRight, ArrowRight, TriangleAlert } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
} from '@/components/ui/select';
import type { ProjectShellProps } from '@/features/projects/types';
import {
    displayValue,
    EffectBadge,
} from '@/features/staged-changes/effect-badge';
import { cn } from '@/lib/utils';
import { canvas, settings } from '@/routes/projects';
import { show, store } from '@/routes/promotions';

type Comparison = App.Data.Promotions.PromotionComparisonData;
type Item = App.Data.Promotions.PromotionItem;

type Props = ProjectShellProps & {
    comparison: Comparison | null;
    cards: {
        from: App.Data.Promotions.PromotionEnvironmentCardData;
        to: App.Data.Promotions.PromotionEnvironmentCardData;
    } | null;
    canDeploy: boolean;
};

type SecretInput = { value: string; copy: boolean };

const kindSign: Record<App.Enums.ChangeKind, { sign: string; label: string }> =
    {
        added: { sign: '+', label: 'Added' },
        removed: { sign: '−', label: 'Removed' },
        changed: { sign: '~', label: 'Changed' },
    };

/**
 * The row's text: what happens in the target, in app vocabulary.
 */
function itemLabel(item: Item, to: string): string {
    if (item.type === 'service') {
        return item.kind === 'removed'
            ? `Remove from ${to}`
            : `New ${item.label} in ${to}`;
    }

    if (item.type === 'triggers') {
        return 'Deploy triggers';
    }

    const verb =
        item.kind === 'added'
            ? 'added'
            : item.kind === 'removed'
              ? 'removed'
              : 'changed';

    return `${item.label.charAt(0).toUpperCase()}${item.label.slice(1)} ${verb}`;
}

function ValueChange({
    item,
    from,
    to,
}: {
    item: Item;
    from: string;
    to: string;
}) {
    if (item.conflict) {
        return (
            <span className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 type-mono-small text-muted-foreground">
                <span>
                    {from}:{' '}
                    <span className="text-foreground">
                        {item.after === null && item.kind === 'removed'
                            ? 'removed'
                            : displayValue(item.after) || 'set'}
                    </span>
                </span>
                <span>
                    {to}:{' '}
                    <span className="text-foreground">
                        {displayValue(item.before) || 'set'}
                    </span>
                </span>
                {item.base !== null && (
                    <span>was {displayValue(item.base)}</span>
                )}
            </span>
        );
    }

    if (
        item.kind !== 'changed' ||
        (item.before === null && item.after === null)
    ) {
        return item.kind === 'added' && item.after !== null ? (
            <span className="type-mono-small break-all text-muted-foreground">
                {displayValue(item.after)}
            </span>
        ) : null;
    }

    return (
        <span className="flex flex-wrap items-baseline gap-x-2 type-mono-small text-muted-foreground">
            <span className="break-all line-through decoration-muted-foreground/60">
                {displayValue(item.before)}
            </span>
            <span aria-hidden="true">→</span>
            <span className="sr-only">changes to</span>
            <span className="break-all text-foreground">
                {displayValue(item.after)}
            </span>
        </span>
    );
}

function ItemRow({
    item,
    checked,
    disabled,
    secret,
    from,
    to,
    onToggle,
    onSecret,
}: {
    item: Item;
    checked: boolean;
    disabled: boolean;
    secret: SecretInput;
    from: string;
    to: string;
    onToggle: (checked: boolean) => void;
    onSecret: (input: SecretInput) => void;
}) {
    const id = `item-${item.path}`;
    const sign = kindSign[item.kind];

    return (
        <li className="flex items-start gap-3 py-3 pr-4">
            <span
                aria-hidden="true"
                className={cn(
                    'flex w-7 shrink-0 justify-center self-stretch border-l-2 pt-0.5 type-mono',
                    item.kind === 'added' && 'border-status-ok text-status-ok',
                    item.kind === 'changed' && 'border-signal text-signal',
                    item.kind === 'removed' &&
                        'border-status-error text-status-error',
                )}
            >
                {sign.sign}
            </span>
            <Checkbox
                id={id}
                className="mt-0.5"
                checked={checked}
                disabled={disabled || !item.selectable}
                onCheckedChange={(next) => onToggle(next === true)}
            />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <Label
                    htmlFor={id}
                    className="flex flex-wrap items-center gap-2 type-body font-normal"
                >
                    <span className="sr-only">{sign.label}:</span>
                    <span>{itemLabel(item, to)}</span>
                    {item.secret && (
                        <Badge variant="outline" className="font-normal">
                            Secret
                        </Badge>
                    )}
                    {item.conflict && (
                        <Badge
                            variant="outline"
                            className="gap-1 font-normal text-status-progress"
                        >
                            <TriangleAlert aria-hidden="true" />
                            Conflict
                        </Badge>
                    )}
                    {item.promotion === 'environment_specific' && (
                        <span className="type-small text-muted-foreground">
                            Environment-specific
                        </span>
                    )}
                </Label>
                <ValueChange item={item} from={from} to={to} />
                {item.conflict && (
                    <span className="type-small text-muted-foreground">
                        Changed in both. Check it to take {from}’s value, or
                        leave it unchecked to keep {to}’s.
                    </span>
                )}
                {item.warning && (
                    <span className="flex items-center gap-1.5 type-small text-status-error">
                        <TriangleAlert
                            aria-hidden="true"
                            className="size-3.5"
                        />
                        {item.warning}
                    </span>
                )}
                {item.note && (
                    <span className="type-small text-muted-foreground">
                        {item.note}
                    </span>
                )}
                {item.acceptsValue && checked && (
                    <div className="flex flex-col gap-2 pt-1 sm:flex-row sm:items-center">
                        <Input
                            type="password"
                            autoComplete="new-password"
                            aria-label={`Value of ${item.key ?? ''} in ${to}`}
                            placeholder={
                                item.needsValue
                                    ? `Value for ${to}`
                                    : `New value for ${to} (optional)`
                            }
                            className="h-8 type-mono-small sm:max-w-72"
                            value={secret.value}
                            disabled={secret.copy || disabled}
                            onChange={(event) =>
                                onSecret({
                                    value: event.target.value,
                                    copy: false,
                                })
                            }
                        />
                        <label
                            className={cn(
                                'flex items-center gap-2 type-small',
                                !item.canCopyValue && 'text-muted-foreground',
                            )}
                        >
                            <Checkbox
                                checked={secret.copy}
                                disabled={!item.canCopyValue || disabled}
                                onCheckedChange={(next) =>
                                    onSecret({ value: '', copy: next === true })
                                }
                            />
                            Copy the value from {from}
                        </label>
                        {item.copyBlockedReason && (
                            <span className="type-small text-muted-foreground">
                                {item.copyBlockedReason}
                            </span>
                        )}
                    </div>
                )}
            </div>
            <EffectBadge effect={item.effect} />
        </li>
    );
}

function Comparison({
    project,
    comparison,
    canDeploy,
}: {
    project: App.Data.Canvas.ProjectData;
    comparison: Comparison;
    canDeploy: boolean;
}) {
    const from = comparison.from.name;
    const to = comparison.to.name;
    const byPath = useMemo(
        () => new Map(comparison.items.map((item) => [item.path, item])),
        [comparison.items],
    );
    const [selected, setSelected] = useState<Set<string>>(
        () =>
            new Set(
                comparison.items
                    .filter((item) => item.selected)
                    .map((item) => item.path),
            ),
    );
    const [secrets, setSecrets] = useState<Record<string, SecretInput>>({});
    const [images, setImages] = useState<Set<string>>(
        () =>
            new Set(
                comparison.images
                    .filter((image) => image.sameInputs)
                    .map((image) => image.serviceId),
            ),
    );
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const disabled = !comparison.canPromote;

    const toggle = (item: Item, checked: boolean) =>
        setSelected((current) => {
            const next = new Set(current);

            if (checked) {
                next.add(item.path);
                // Dependencies come along (a variable needs its service).
                item.dependsOn.forEach((path) => {
                    if (byPath.get(path)?.selectable) {
                        next.add(path);
                    }
                });
            } else {
                next.delete(item.path);
                // Dependents go too.
                comparison.items.forEach((other) => {
                    if (other.dependsOn.includes(item.path)) {
                        next.delete(other.path);
                    }
                });
            }

            return next;
        });

    const groups = useMemo(() => {
        const services = new Map(
            comparison.services.map((service) => [service.id, service]),
        );
        const result: {
            key: string;
            title: string;
            subtitle: string | null;
            items: Item[];
        }[] = [];

        comparison.services.forEach((service) => {
            const items = comparison.items.filter(
                (item) =>
                    item.serviceId === service.id &&
                    !item.path.startsWith('connections.'),
            );
            const connections = comparison.items.filter(
                (item) =>
                    item.path.startsWith('connections.') &&
                    item.serviceId === service.id,
            );

            if (items.length + connections.length > 0) {
                result.push({
                    key: service.id,
                    title: service.name,
                    subtitle: service.kind,
                    items: [...items, ...connections],
                });
            }
        });

        const project = comparison.items.filter(
            (item) => item.serviceId === null || !services.has(item.serviceId),
        );

        if (project.length > 0) {
            result.push({
                key: 'project',
                title: 'Shared variables and environment',
                subtitle: null,
                items: project,
            });
        }

        return result;
    }, [comparison]);

    const count = [...selected].filter((path) => byPath.has(path)).length;
    const restarts = [
        ...new Set(
            comparison.items
                .filter(
                    (item) =>
                        selected.has(item.path) &&
                        item.effect !== 'none' &&
                        item.serviceId,
                )
                .map(
                    (item) =>
                        comparison.services.find(
                            (service) => service.id === item.serviceId,
                        )?.name,
                )
                .filter((name): name is string => Boolean(name)),
        ),
    ];
    const missingValues = comparison.items.filter((item) => {
        const input = secrets[item.path];

        return (
            selected.has(item.path) &&
            item.needsValue &&
            !(input?.copy || (input?.value ?? '') !== '')
        );
    });

    const blocked =
        processing ||
        (count === 0 && images.size === 0) ||
        missingValues.length > 0;

    const submit = (deploy: boolean) => {
        setProcessing(true);
        setError(null);

        router.post(
            store(project.id).url,
            {
                from: comparison.from.id,
                to: comparison.to.id,
                deploy,
                items: [...selected]
                    .filter((path) => byPath.has(path))
                    .map((path) => ({
                        path,
                        value:
                            secrets[path]?.value === ''
                                ? null
                                : (secrets[path]?.value ?? null),
                        copyValue: secrets[path]?.copy ?? false,
                    })),
                images: [...images],
            },
            {
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ??
                            'The changes could not be staged.',
                    ),
                onFinish: () => setProcessing(false),
            },
        );
    };

    if (comparison.items.length === 0 && comparison.images.length === 0) {
        return (
            <section className="flex flex-col items-start gap-3">
                <p className="max-w-[65ch] type-body text-muted-foreground">
                    {from} has no changes that aren’t in {to}.{' '}
                    {comparison.baseDescription}
                </p>
                <Button variant="outline" size="sm" asChild>
                    <Link href={canvas([project.id, comparison.from.id]).url}>
                        Open {from}
                    </Link>
                </Button>
            </section>
        );
    }

    return (
        <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_300px]">
            <div className="flex flex-col gap-4">
                {groups.map((group) => (
                    <section
                        key={group.key}
                        aria-labelledby={`group-${group.key}`}
                        className="overflow-hidden rounded-[14px] border bg-card shadow-card"
                    >
                        <div className="flex items-baseline gap-2 border-b px-4 py-3">
                            <h2
                                id={`group-${group.key}`}
                                className="type-heading"
                            >
                                {group.title}
                            </h2>
                            {group.subtitle && (
                                <span className="type-small text-muted-foreground">
                                    {group.subtitle}
                                </span>
                            )}
                        </div>
                        <ul className="flex flex-col divide-y">
                            {group.items.map((item) => (
                                <ItemRow
                                    key={item.path}
                                    item={item}
                                    checked={selected.has(item.path)}
                                    disabled={disabled}
                                    secret={
                                        secrets[item.path] ?? {
                                            value: '',
                                            copy: false,
                                        }
                                    }
                                    from={from}
                                    to={to}
                                    onToggle={(checked) =>
                                        toggle(item, checked)
                                    }
                                    onSecret={(input) =>
                                        setSecrets((current) => ({
                                            ...current,
                                            [item.path]: input,
                                        }))
                                    }
                                />
                            ))}
                        </ul>
                    </section>
                ))}
            </div>

            <aside className="flex flex-col gap-6 self-start lg:sticky lg:top-0">
                {comparison.images.length > 0 && (
                    <section
                        aria-labelledby="images-title"
                        className="flex flex-col gap-3 rounded-[14px] border bg-card p-4 shadow-card"
                    >
                        <div className="flex flex-col gap-1">
                            <h2 id="images-title" className="type-label">
                                Also promote images
                            </h2>
                            <p className="type-small text-muted-foreground">
                                {to} deploys the commit that runs in {from}. If
                                the build inputs match, the image is reused.
                                Otherwise it’s rebuilt with {to}’s values.
                            </p>
                        </div>
                        <ul className="flex flex-col gap-3">
                            {comparison.images.map((image) => (
                                <li
                                    key={image.serviceId}
                                    className="flex items-start gap-3"
                                >
                                    <Checkbox
                                        id={`image-${image.serviceId}`}
                                        className="mt-0.5"
                                        checked={images.has(image.serviceId)}
                                        disabled={disabled}
                                        onCheckedChange={(next) =>
                                            setImages((current) => {
                                                const copy = new Set(current);

                                                if (next === true) {
                                                    copy.add(image.serviceId);
                                                } else {
                                                    copy.delete(
                                                        image.serviceId,
                                                    );
                                                }

                                                return copy;
                                            })
                                        }
                                    />
                                    <Label
                                        htmlFor={`image-${image.serviceId}`}
                                        className="flex flex-col items-start gap-0.5 type-body font-normal"
                                    >
                                        <span>
                                            {image.name}{' '}
                                            <span className="type-mono-small text-muted-foreground">
                                                {image.commitSha?.slice(0, 7)}
                                            </span>
                                        </span>
                                        <span className="type-small text-muted-foreground">
                                            {image.sameInputs
                                                ? 'Build inputs match, so the image is reused.'
                                                : `Rebuilt in ${to}: ${image.differences.join(', ')} differ.`}
                                        </span>
                                    </Label>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section className="flex flex-col gap-3 rounded-[14px] border bg-card p-4 shadow-card">
                    <p className="type-body">
                        <span className="font-medium tabular-nums">
                            {count} {count === 1 ? 'change' : 'changes'}
                        </span>
                        {images.size > 0 && (
                            <span className="tabular-nums">
                                {' '}
                                and {images.size}{' '}
                                {images.size === 1 ? 'image' : 'images'}
                            </span>
                        )}{' '}
                        to {to}
                    </p>
                    {restarts.length > 0 && (
                        <p className="type-small text-muted-foreground">
                            Deploying restarts{' '}
                            {restarts.length > 1
                                ? `${restarts.slice(0, -1).join(', ')} and ${restarts.at(-1)}`
                                : restarts[0]}{' '}
                            in {to}.
                            {comparison.targetHasStagedChanges &&
                                ` ${to}’s staged changes deploy with it.`}
                        </p>
                    )}
                    {error && (
                        <p
                            role="alert"
                            className="type-small text-status-error"
                        >
                            {error}
                        </p>
                    )}
                    {missingValues.length > 0 && (
                        <p className="type-small text-muted-foreground">
                            Enter the {to} value for{' '}
                            {missingValues
                                .map((item) => item.key ?? item.label)
                                .join(', ')}
                            , or copy it.
                        </p>
                    )}
                    {comparison.canPromote ? (
                        <div className="flex flex-col gap-2">
                            {canDeploy && (
                                <Button
                                    onClick={() => submit(true)}
                                    disabled={blocked}
                                >
                                    Promote and deploy
                                </Button>
                            )}
                            <Button
                                variant={canDeploy ? 'outline' : 'default'}
                                onClick={() => submit(false)}
                                disabled={blocked}
                            >
                                Stage only
                            </Button>
                            <p className="type-small text-muted-foreground">
                                {canDeploy
                                    ? `Stage only adds them to ${to}’s staged changes for review.`
                                    : `They’re added to ${to}’s staged changes for someone who can deploy.`}
                            </p>
                        </div>
                    ) : (
                        <p className="type-small text-muted-foreground">
                            Promoting into {to} needs the developer role or
                            higher.
                        </p>
                    )}
                </section>
            </aside>
        </div>
    );
}

function EnvironmentCard({
    card,
    role,
    environments,
    exclude,
    onChange,
}: {
    card: App.Data.Promotions.PromotionEnvironmentCardData;
    role: 'From' | 'To';
    environments: App.Data.Promotions.PromotionEnvironmentData[];
    exclude: string;
    onChange: (id: string) => void;
}) {
    return (
        <div className="flex min-w-0 flex-1 flex-col gap-3 rounded-[14px] border bg-card p-4 shadow-card">
            <div className="flex items-center justify-between gap-3">
                <span className="type-small text-muted-foreground">{role}</span>
                <Select value={card.id} onValueChange={onChange}>
                    <SelectTrigger
                        size="sm"
                        className="h-7 w-auto gap-1.5 border-none px-2 shadow-none"
                        aria-label={`${role} environment`}
                    >
                        <span className="type-small text-muted-foreground">
                            Change
                        </span>
                    </SelectTrigger>
                    <SelectContent align="end">
                        {environments
                            .filter((environment) => environment.id !== exclude)
                            .map((environment) => (
                                <SelectItem
                                    key={environment.id}
                                    value={environment.id}
                                >
                                    {environment.name}
                                </SelectItem>
                            ))}
                    </SelectContent>
                </Select>
            </div>
            <div className="flex flex-col gap-1">
                <span className="truncate type-heading">{card.name}</span>
                <span className="type-small text-muted-foreground">
                    {card.revision !== null ? (
                        <MetaLine>
                            <span>Revision {card.revision}</span>
                            {card.deployedAt && (
                                <span>
                                    Deployed <TimeAgo value={card.deployedAt} />
                                </span>
                            )}
                        </MetaLine>
                    ) : (
                        'Not deployed yet'
                    )}
                </span>
            </div>
            <span className="type-small text-muted-foreground tabular-nums">
                {card.live} of {card.services}{' '}
                {card.services === 1 ? 'service' : 'services'} live
            </span>
        </div>
    );
}

/**
 * Compare & promote (flows §12, direction §9.10): the two environments as
 * cards with the direction between them (swap on click), the changes per
 * service with distinct + / ~ / − gutters, secrets inline, and
 * **Promote and deploy** (stage and deploy in one step) with "Stage only"
 * as the secondary.
 */
export default function PromotePage({
    project,
    comparison,
    cards,
    canDeploy,
}: Props) {
    const navigate = (from: string, to: string) =>
        router.visit(show(project.id, { query: { from, to } }).url, {
            preserveState: false,
        });
    const offered = comparison?.offered ?? 0;

    return (
        <>
            <Head title={`Compare & promote - ${project.name}`} />
            <Page>
                <PageHeader
                    title="Compare & promote"
                    description={
                        comparison
                            ? offered > 0
                                ? `${offered} ${offered === 1 ? 'change' : 'changes'} in ${comparison.from.name} that ${offered === 1 ? 'isn’t' : 'aren’t'} in ${comparison.to.name}`
                                : comparison.baseDescription
                            : 'Move configuration between environments without redoing it by hand.'
                    }
                />

                {comparison && cards && (
                    <div className="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
                        <EnvironmentCard
                            card={cards.from}
                            role="From"
                            environments={comparison.environments}
                            exclude={comparison.to.id}
                            onChange={(id) => navigate(id, comparison.to.id)}
                        />
                        <Button
                            variant="outline"
                            size="icon"
                            className="self-center rounded-full"
                            aria-label={`Swap: promote ${comparison.to.name} to ${comparison.from.name}`}
                            title="Swap direction"
                            onClick={() =>
                                navigate(comparison.to.id, comparison.from.id)
                            }
                        >
                            <ArrowRight
                                aria-hidden="true"
                                className="max-sm:rotate-90"
                            />
                        </Button>
                        <EnvironmentCard
                            card={cards.to}
                            role="To"
                            environments={comparison.environments}
                            exclude={comparison.from.id}
                            onChange={(id) => navigate(comparison.from.id, id)}
                        />
                    </div>
                )}

                {comparison ? (
                    <Comparison
                        key={`${comparison.from.id}-${comparison.to.id}`}
                        project={project}
                        comparison={comparison}
                        canDeploy={canDeploy}
                    />
                ) : (
                    <EmptyState
                        icon={ArrowLeftRight}
                        action={
                            <Button variant="outline" asChild>
                                <Link
                                    href={
                                        settings({
                                            project: project.id,
                                            section: 'environments',
                                        }).url
                                    }
                                >
                                    Manage environments
                                </Link>
                            </Button>
                        }
                    >
                        This project has one environment. Create another, like
                        staging, to promote between them.
                    </EmptyState>
                )}
            </Page>
        </>
    );
}
