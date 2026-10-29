<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Server;

/**
 * Optional protocol features a launchroomd announces in Report.host.features
 * (docs/03 §10). Decoding is strict on the daemon, so an optional NodeState
 * field is rendered only for servers that announced its feature
 * ({@see Server::supportsDaemonFeature()}). Unknown names are
 * ignored when stored, so newer daemons can announce more.
 */
enum DaemonFeature: string
{
    /** `system.linkProbe` and `Report.host.links` (docs/05 §2.1). */
    case LinkProbe = 'linkProbe';
    /** `WorkloadSpec.shipLogs` (runtime log shipping). */
    case ShipLogs = 'shipLogs';
    /** `WorkloadSpec.walArchive`, `Report.walArchives`, base_backup backups/restores (docs/14 §10). */
    case PointInTime = 'pointInTime';
    /** The `verify_restore` task (docs/14 §10.4). */
    case VerifyRestore = 'verifyRestore';
    /** `WorkloadSpec.stop`: stop signal, timeout, no init (D42). */
    case StopSpec = 'stopSpec';
    /** Console `session` tasks, allowed by the server's local LR_INTERACTIVE_SESSIONS (docs/03 §11). */
    case ConsoleSessions = 'consoleSessions';
    /** Data `session` tasks, likewise. */
    case DataSessions = 'dataSessions';
    /** `system.controlPlane` (the canonical dashboard origin) and `Report.host.controlPlane` (docs/05 §4.1). */
    case ControlPlaneUrl = 'controlPlaneUrl';
    /** Build `repo.provider` gitlab/gitea, ssh clone URLs with `repo.knownHosts`, `sshKey`, `repo.ref` (docs/19 §6). */
    case GitProviders = 'gitProviders';
    /** Build `builder.dockerfile` (inline), plan `inspect` + `TaskCompletion.inspection`, run_once `probe` (docs/20 §6). */
    case GeneratedDockerfile = 'generatedDockerfile';
    /** The `mongodb` and `clickhouse` engines in backup, restore, verify_restore and data session tasks (D47). */
    case MongoClickHouse = 'mongoClickhouse';
    /** `ingress.dns` providers beyond Cloudflare/Hetzner, with per-provider credentials (D47, docs/05 §5). */
    case DnsProviders = 'dnsProviders';
    /** `WorkloadSpec.schedule`: scheduled cron services (docs/04 §1). */
    case CronSchedule = 'cronSchedule';
    /** `RouteSpec.clientAuth` and POST /daemon/v1/client-certificate (mTLS, D50, docs/03 §4.3). */
    case ClientCertificates = 'clientCertificates';
    /** The db_import, volume_import, db_export and volume_export tasks and the transfer chunk endpoints (D55, docs/14 §12). */
    case DataTransfer = 'dataTransfer';
    /** NodeState volumes no workload mounts are created too (owner-less), so a moving service's volumes exist before it runs (docs/14 §13.2, D56). */
    case PreparedVolumes = 'preparedVolumes';
    /** `Report.metrics.analytics`: top paths, referrer hosts and client classes per route and 10 minutes (docs/15 §5). */
    case RequestAnalytics = 'requestAnalytics';
    /** `access` tasks of kind ssh and `TaskHeartbeat.access` (D61, docs/03 §12). */
    case RemoteAccessSsh = 'remoteAccessSsh';
    /** `access` tasks of kind database (D61, docs/03 §12). */
    case RemoteAccessDatabase = 'remoteAccessDatabase';
    /** `access` tasks of kind volume: SFTP into one volume through a helper container (D67, docs/03 §12.4). */
    case RemoteAccessVolume = 'remoteAccessVolume';
    /** The `inventory` task (docs/22 §3.2, docs/03 §13.1). */
    case Inventory = 'inventory';
    /** The `inspect_container` task (docs/22 §7.2, docs/03 §13.2). */
    case ContainerInspection = 'containerInspection';
    /** `RouteSpec.waf`, `Report.ingress.waf` and `Report.metrics.waf`: the web firewall (docs/22 §6, docs/03 §13.3–13.4). */
    case Waf = 'waf';
    /** `WorkloadSpec.files`: config files written into the container before it starts (D65, docs/03 §6). */
    case ConfigFiles = 'configFiles';
    /** `RouteSpec.stopped`: the edge answers a stopped service's domains with its "stopped" page (D68). */
    case ServiceStop = 'serviceStop';
    /** `BuilderSpec.phpExtensions` and `BuildSummary.phpExtensions`: database drivers added to PHP builds (docs/04 §3.1). */
    case PhpExtensions = 'phpExtensions';
}
