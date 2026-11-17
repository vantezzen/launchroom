import { KeyRound, TriangleAlert } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { DatabaseChoice } from './database-picker';

type Plan = App.Data.Canvas.DetectedPlanData;
type Engine = App.Data.Canvas.DatabaseEngineOptionData;

/** Kinds of database an app keeps its data in (a fallback suggestion is skipped when one is there). */
const PRIMARY_CATEGORIES: Engine['category'][] = ['relational', 'document'];

function categoryOf(
    engines: Engine[],
    engine: string,
): Engine['category'] | null {
    return engines.find((item) => item.engine === engine)?.category ?? null;
}

/**
 * Whether a fallback suggestion (Postgres for a Laravel app on SQLite)
 * should join: only when nothing of its kind is connected to the app yet,
 * picked or unticked by the user.
 */
export function fallbackApplies(
    suggestion: App.Data.Projects.SuggestedDatabaseData,
    current: DatabaseChoice[],
    engines: Engine[],
    root: string,
): boolean {
    if (!suggestion.fallback) {
        return true;
    }

    const category = categoryOf(engines, suggestion.engine);

    return !current.some(
        (db) =>
            db.connectTo.includes(root) &&
            categoryOf(engines, db.engine) === category,
    );
}

/**
 * Whether a Laravel app is left without a database it keeps data in.
 */
export function laravelWithoutDatabase(
    plan: Plan | null,
    databases: DatabaseChoice[],
    engines: Engine[],
    root: string,
): boolean {
    if (plan?.framework !== 'Laravel') {
        return false;
    }

    return !databases.some((db) => {
        const category = categoryOf(engines, db.engine);

        return (
            db.selected &&
            db.connectTo.includes(root) &&
            category !== null &&
            PRIMARY_CATEGORIES.includes(category)
        );
    });
}

/**
 * The import card's asks (docs/08 §13): secrets the plan needs that
 * launchroom can't generate (pasted here, created as secret variables of
 * the app in production), and the SQLite warning for a Laravel app without
 * a database.
 */
export function ImportPlanAsks({
    plan,
    root,
    secrets,
    onSecrets,
    withoutDatabase,
}: {
    plan: Plan | null;
    root: string;
    secrets: Record<string, string>;
    onSecrets: (secrets: Record<string, string>) => void;
    withoutDatabase: boolean;
}) {
    const required = plan?.requiredSecrets ?? [];

    if (required.length === 0 && !withoutDatabase) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3 border-t pt-4">
            {required.length > 0 && (
                <div className="flex flex-col gap-3">
                    <span className="flex items-center gap-2 type-label">
                        <KeyRound
                            aria-hidden="true"
                            className="size-3.5 shrink-0 text-muted-foreground"
                        />
                        Needed before the first deploy
                    </span>
                    {required.map((secret) => {
                        const id = `secret-${root || 'root'}-${secret.name}`;

                        return (
                            <div
                                key={secret.name}
                                className="flex flex-col gap-1.5"
                            >
                                <Label htmlFor={id} className="type-mono-small">
                                    {secret.name}
                                </Label>
                                <Input
                                    id={id}
                                    type="password"
                                    autoComplete="off"
                                    spellCheck={false}
                                    value={secrets[secret.name] ?? ''}
                                    onChange={(event) =>
                                        onSecrets({
                                            ...secrets,
                                            [secret.name]: event.target.value,
                                        })
                                    }
                                />
                                {secret.reason && (
                                    <p className="type-small text-muted-foreground">
                                        {secret.reason}
                                    </p>
                                )}
                            </div>
                        );
                    })}
                    <p className="type-small text-muted-foreground">
                        Saved as a secret of this app. You can also add it later
                        under Variables. A deploy without it stops before the
                        app starts.
                    </p>
                </div>
            )}
            {withoutDatabase && (
                <p className="flex gap-2.5 type-small">
                    <TriangleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-3.5 shrink-0 text-status-progress"
                    />
                    <span>
                        <span className="sr-only">Warning: </span>
                        Without a database, Laravel keeps its data in a SQLite
                        file inside the container. Every deploy starts it empty,
                        and pages that use sessions fail.
                    </span>
                </p>
            )}
        </div>
    );
}
