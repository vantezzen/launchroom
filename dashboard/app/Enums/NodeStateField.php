<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The optional NodeState fields only some launchroomd versions accept: the
 * feature a server must announce for each (Report.host.features, docs/03
 * §10) and what the renderer does for servers without it.
 */
enum NodeStateField: string
{
    case LinkProbe = 'system.linkProbe';
    case ControlPlane = 'system.controlPlane';
    case RouteClientAuth = 'routes[].clientAuth';
    case RouteWaf = 'routes[].waf';
    case RouteStopped = 'routes[].stopped';
    case PreparedVolume = 'volumes[]';
    case SharedVolume = 'volumes[].service';
    case WorkloadShipLogs = 'workloads[].shipLogs';
    case WorkloadFiles = 'workloads[].files';
    case WorkloadSchedule = 'workloads[].schedule';
    case WorkloadStop = 'workloads[].stop';
    case WorkloadWalArchive = 'workloads[].walArchive';
    case IngressDnsProvider = 'ingress.dns.provider';
    case SystemWatchdog = 'system.watchdog';
    case HealthHost = 'workloads[].health.host';
    case HealthRestartAfter = 'workloads[].health.restartAfter';
    case MountInitOwner = 'workloads[].mounts[].initOwner';

    public function feature(): DaemonFeature
    {
        return match ($this) {
            self::LinkProbe => DaemonFeature::LinkProbe,
            self::ControlPlane => DaemonFeature::ControlPlaneUrl,
            self::RouteClientAuth => DaemonFeature::ClientCertificates,
            self::RouteWaf => DaemonFeature::Waf,
            self::RouteStopped => DaemonFeature::ServiceStop,
            self::PreparedVolume => DaemonFeature::PreparedVolumes,
            self::SharedVolume => DaemonFeature::SharedVolumes,
            self::WorkloadShipLogs => DaemonFeature::ShipLogs,
            self::WorkloadFiles => DaemonFeature::ConfigFiles,
            self::WorkloadSchedule => DaemonFeature::CronSchedule,
            self::WorkloadStop => DaemonFeature::StopSpec,
            self::WorkloadWalArchive => DaemonFeature::PointInTime,
            self::IngressDnsProvider => DaemonFeature::DnsProviders,
            self::SystemWatchdog => DaemonFeature::Watchdog,
            self::HealthHost => DaemonFeature::ProbeHost,
            self::HealthRestartAfter => DaemonFeature::LivenessRestart,
            self::MountInitOwner => DaemonFeature::VolumeInitOwner,
        };
    }

    /**
     * Without the feature: a volume no workload mounts (it waits for a
     * moving service) is left out; a stopped service's domains get no route
     * (they hold their hosts against deployment URLs); a cron runs its
     * command continuously; a
     * database keeps Docker's default stop and doesn't archive WAL; a
     * workload that mounts another service's volume is left out (with its
     * routes); generated domains get no wildcard certificate from a DNS
     * provider the daemon doesn't know; the watchdog is off; the probe
     * sends the container address as Host and never restarts; a new volume
     * keeps Docker's owner.
     */
    public function downgrade(): FeatureDowngrade
    {
        return match ($this) {
            self::LinkProbe, self::ControlPlane, self::RouteClientAuth, self::RouteWaf,
            self::WorkloadShipLogs, self::WorkloadFiles, self::SystemWatchdog, self::HealthHost,
            self::HealthRestartAfter, self::MountInitOwner => FeatureDowngrade::DropField,
            self::PreparedVolume => FeatureDowngrade::DropItem,
            self::RouteStopped, self::SharedVolume, self::WorkloadSchedule, self::WorkloadStop,
            self::WorkloadWalArchive, self::IngressDnsProvider => FeatureDowngrade::RenderWithout,
        };
    }
}
