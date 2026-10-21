import type { Metadata } from 'next';
import { Screenshot } from '@/components/screenshot';
import { FactGrid, FeatureFooter, FeatureHero } from '@/components/marketing/feature-page';
import { FeatureRow, Section } from '@/components/marketing/section';
import { RecoveryCard } from '@/components/marketing/visuals';

export const metadata: Metadata = {
    title: 'Databases and backups',
    description:
        'PostgreSQL, MySQL, Redis, Valkey, MongoDB and ClickHouse on your own server, with encrypted backups to your S3 and point-in-time recovery.',
    alternates: { canonical: '/features/databases' },
};

export default function Page() {
    return (
        <>
            <FeatureHero
                label="Databases and backups"
                title="Lose the server, keep everything."
                lead="Add a database to the canvas and connect it to your app. Backups go to your own S3-compatible bucket, encrypted before they leave the server, and launchroom checks every week that they restore."
                docsHref="/docs/databases/managed-databases"
                visual={
                    <Screenshot
                        id="database-backups"
                        preload
                        caption={false}
                        sizes="(min-width: 1200px) 1180px, 100vw"
                        frameless
                    />
                }
            />
            <Section tone="frame" labelledBy="engines-title">
                <FeatureRow
                    id="engines-title"
                    label="Managed databases"
                    title="Drop it on the canvas. It’s connected."
                    visual={<Screenshot id="add-database" caption={false} sizes="(min-width: 1024px) 700px, 100vw" />}
                    points={[
                        'PostgreSQL, MySQL, Redis, Valkey, MongoDB and ClickHouse',
                        'Credentials are generated and given to the apps you connect',
                        'Data lives on named volumes and survives every redeploy',
                    ]}
                    more={{ href: '/docs/databases/managed-databases', text: 'Managed databases' }}
                >
                    Draw a line from your app to the database. The app receives the connection details with the variable
                    names its framework expects. Nobody copies a password.
                </FeatureRow>
            </Section>
            <Section labelledBy="pitr-title">
                <FeatureRow
                    id="pitr-title"
                    label="Point-in-time recovery"
                    title="Restore Postgres to the second."
                    reverse
                    visual={<Screenshot id="pitr" caption={false} sizes="(min-width: 1024px) 700px, 100vw" />}
                    points={[
                        'Changes are shipped every 30 seconds, plus a daily full backup',
                        'Restore as a new database next to the old one, or in place',
                        'Move apps and domains to the restored copy in one step',
                    ]}
                    more={{ href: '/docs/databases/point-in-time-recovery', text: 'Point-in-time recovery' }}
                >
                    Pick a moment before the bad migration. launchroom rebuilds the database up to that second and hands
                    you the result.
                </FeatureRow>
            </Section>
            <Section tone="frame" labelledBy="recovery-title">
                <FeatureRow
                    id="recovery-title"
                    label="Disaster recovery"
                    title="One command brings it all back."
                    visual={<RecoveryCard />}
                    points={[
                        'launchroom backs up its own database and keys, sealed with your recovery passphrase',
                        'The recovery kit holds what a new server needs',
                        'Recovery mode guides you through replacing the lost server',
                    ]}
                    more={{
                        href: '/docs/databases/restore-and-disaster-recovery',
                        text: 'Restore and disaster recovery',
                    }}
                >
                    If the server is gone, run the installer with --restore on a new machine. Your projects, secrets,
                    private network and databases come back.
                </FeatureRow>
            </Section>
            <Section labelledBy="data-title">
                <FeatureRow
                    id="data-title"
                    label="Data tab"
                    title="Look inside without a database client."
                    reverse
                    visual={<Screenshot id="database-data" caption={false} sizes="(min-width: 1024px) 700px, 100vw" />}
                    more={{ href: '/docs/observability/console-and-data', text: 'Console and Data' }}
                >
                    Browse tables and run queries in the dashboard. It is read-only by default. Writes are allowed per
                    session and recorded statement by statement.
                </FeatureRow>
            </Section>
            <Section tone="frame" labelledBy="facts-title">
                <h2 id="facts-title" className="sr-only">
                    Details
                </h2>
                <FactGrid
                    facts={[
                        { title: 'Encrypted on the server', text: 'Backups are encrypted before they are uploaded.' },
                        { title: 'Any S3', text: 'Cloudflare R2, Hetzner Object Storage, AWS S3, MinIO and others.' },
                        { title: 'Volumes too', text: 'Named volumes back up on the same schedules.' },
                        { title: 'Final backups', text: 'Deleting a database takes one last backup first.' },
                        {
                            title: 'Checked weekly',
                            text: 'Backups are restored into throwaway containers and verified.',
                        },
                        { title: 'Alerts', text: 'A failed or missing backup sends an alert.' },
                    ]}
                />
            </Section>
            <FeatureFooter current="/features/databases" />
        </>
    );
}
