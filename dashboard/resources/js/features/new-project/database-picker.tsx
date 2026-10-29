import { Check } from 'lucide-react';
import { Logo } from '@/components/logo';
import { MetaLine } from '@/components/patterns/meta-line';
import { cn } from '@/lib/utils';

type Engine = App.Data.Canvas.DatabaseEngineOptionData;

export type DatabaseChoice = {
    key: string;
    engine: App.Enums.DatabaseEngine;
    name: string;
    /** Why it was suggested (`.env.example`, the code); null when added here. */
    reason: string | null;
    connectTo: string[];
    selected: boolean;
};

type AppRef = { rootDirectory: string; name: string; selected: boolean };

/** Frameworks whose usual setup is a Postgres database. */
const POSTGRES_FIRST = /laravel|rails|django|phoenix|adonis/i;
/** Processes that point at a queue (a Redis-backed worker). */
const QUEUE = /queue|worker|horizon|sidekiq|celery|bull/i;

/**
 * The engine that fits what was detected, for a quiet "Recommended" mark:
 * Postgres for the usual web frameworks, Redis when a queue worker was
 * found. Never one the repository already asks for.
 */
export function recommendedEngines(
    frameworks: string[],
    processes: string[],
    taken: string[],
): string[] {
    const picks: string[] = [];

    if (
        frameworks.some((framework) => POSTGRES_FIRST.test(framework)) &&
        !taken.some((engine) => engine === 'postgres' || engine === 'mysql')
    ) {
        picks.push('postgres');
    }

    if (
        processes.some((process) => QUEUE.test(process)) &&
        !taken.some((engine) => engine === 'redis' || engine === 'valkey')
    ) {
        picks.push('redis');
    }

    return picks;
}

/**
 * "Databases" on the import step (docs/08 §13): what the repository asks
 * for on top (detected, connected for you), then every engine launchroom
 * runs as a tile. One click adds a database connected to the picked apps;
 * a second click takes it out again.
 */
export function DatabasePicker({
    engines,
    databases,
    apps,
    frameworks,
    processes,
    onChange,
}: {
    engines: Engine[];
    databases: DatabaseChoice[];
    apps: AppRef[];
    frameworks: string[];
    processes: string[];
    onChange: (databases: DatabaseChoice[]) => void;
}) {
    const selectedApps = apps.filter((app) => app.selected);
    const detected = databases.filter((db) => db.reason !== null);
    const recommended = recommendedEngines(
        frameworks,
        processes,
        detected.map((db) => db.engine),
    );
    const labelOf = (engine: string) =>
        engines.find((item) => item.engine === engine)?.label ?? engine;
    const isOn = (engine: string) =>
        databases.some((db) => db.engine === engine && db.selected);
    const php = frameworks.some((framework) =>
        /laravel|php|symfony/i.test(framework),
    );

    const toggle = (engine: Engine) => {
        const existing = databases.find((db) => db.engine === engine.engine);

        if (existing && existing.reason !== null) {
            onChange(
                databases.map((db) =>
                    db === existing ? { ...db, selected: !db.selected } : db,
                ),
            );

            return;
        }

        onChange(
            existing
                ? databases.filter((db) => db !== existing)
                : [
                      ...databases,
                      {
                          key: engine.engine,
                          engine: engine.engine,
                          name: engine.engine,
                          reason: null,
                          connectTo: selectedApps.map(
                              (app) => app.rootDirectory,
                          ),
                          selected: true,
                      },
                  ],
        );
    };

    const added = databases.filter((db) => db.selected);
    const connectsTo = selectedApps.map((app) => app.name).join(', ');

    return (
        <div className="flex flex-col gap-4">
            {detected.length > 0 && (
                <ul className="divide-y overflow-hidden rounded-[14px] border bg-card shadow-card">
                    {detected.map((db) => (
                        <li
                            key={db.key}
                            className={cn(
                                'flex items-center gap-4 px-5 py-4 transition-opacity duration-base',
                                !db.selected && 'opacity-60',
                            )}
                        >
                            <Logo
                                kind={db.engine}
                                size={32}
                                name={labelOf(db.engine)}
                            />
                            <div className="flex min-w-0 flex-1 flex-col">
                                <span className="truncate type-label">
                                    Your app asks for {labelOf(db.engine)}
                                </span>
                                <MetaLine className="type-small text-muted-foreground">
                                    {db.selected
                                        ? `Connects to ${apps
                                              .filter(
                                                  (app) =>
                                                      app.selected &&
                                                      db.connectTo.includes(
                                                          app.rootDirectory,
                                                      ),
                                              )
                                              .map((app) => app.name)
                                              .join(', ')}`
                                        : 'Not added'}
                                    {db.reason}
                                </MetaLine>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <ul
                aria-label={
                    detected.length > 0 ? 'Other databases' : 'Databases'
                }
                className="grid grid-cols-2 gap-3 md:grid-cols-3"
            >
                {engines.map((engine) => {
                    const on = isOn(engine.engine);
                    const pick = recommended.includes(engine.engine);

                    return (
                        <li key={engine.engine}>
                            <button
                                type="button"
                                aria-pressed={on}
                                onClick={() => toggle(engine)}
                                className={cn(
                                    'group relative flex h-full w-full flex-col overflow-hidden rounded-[14px] border bg-card text-left shadow-card transition-[border-color,box-shadow] duration-fast hover:border-border-strong focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none',
                                    on &&
                                        'border-foreground ring-[0.5px] ring-foreground hover:border-foreground',
                                )}
                            >
                                <span
                                    aria-hidden="true"
                                    className="relative flex h-[68px] items-center justify-center border-b bg-recessed"
                                >
                                    <Logo
                                        kind={engine.engine}
                                        name={engine.label}
                                        size={44}
                                        decorative
                                        className="shadow-card"
                                    />
                                    <span
                                        className={cn(
                                            'absolute top-2.5 right-2.5 flex size-5 items-center justify-center rounded-full border transition-colors duration-fast',
                                            on
                                                ? 'border-foreground bg-foreground text-background'
                                                : 'border-border-strong bg-card text-transparent',
                                        )}
                                    >
                                        <Check
                                            className="size-3"
                                            strokeWidth={3}
                                        />
                                    </span>
                                </span>
                                <span className="flex min-w-0 flex-1 flex-col gap-0.5 px-4 py-3">
                                    <span className="flex min-w-0 items-center gap-2">
                                        <span className="truncate type-label text-foreground">
                                            {engine.label}
                                        </span>
                                        {pick && !on && (
                                            <span className="shrink-0 rounded-full border px-1.5 text-[11px] leading-[18px] text-muted-foreground">
                                                Recommended
                                            </span>
                                        )}
                                    </span>
                                    <span className="line-clamp-1 type-small text-muted-foreground">
                                        {engine.tagline}
                                    </span>
                                </span>
                            </button>
                        </li>
                    );
                })}
            </ul>

            <p aria-live="polite" className="type-small text-muted-foreground">
                {added.length === 0
                    ? 'Pick the databases your app needs. launchroom creates the credentials and connects them.'
                    : `${added.map((db) => labelOf(db.engine)).join(' and ')} ${added.length === 1 ? 'connects' : 'connect'} to ${connectsTo || 'your app'}.${php ? ' launchroom installs the PHP driver for you.' : ''}`}
            </p>
        </div>
    );
}
