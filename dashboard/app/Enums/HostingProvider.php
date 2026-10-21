<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Hosting providers recognisable from a server's DMI fields
 * (docs/design/direction.md §7.3). Display only; never used for decisions.
 */
enum HostingProvider: string
{
    case Hetzner = 'hetzner';
    case DigitalOcean = 'digitalocean';
    case Aws = 'aws';
    case Gcp = 'gcp';
    case Azure = 'azure';
    case Linode = 'linode';
    case Vultr = 'vultr';
    case Ovh = 'ovh';
    case Scaleway = 'scaleway';
    case Oracle = 'oracle';
    case UpCloud = 'upcloud';
    case Exoscale = 'exoscale';

    public function label(): string
    {
        return match ($this) {
            self::Hetzner => 'Hetzner',
            self::DigitalOcean => 'DigitalOcean',
            self::Aws => 'AWS',
            self::Gcp => 'Google Cloud',
            self::Azure => 'Azure',
            self::Linode => 'Akamai (Linode)',
            self::Vultr => 'Vultr',
            self::Ovh => 'OVHcloud',
            self::Scaleway => 'Scaleway',
            self::Oracle => 'Oracle Cloud',
            self::UpCloud => 'UpCloud',
            self::Exoscale => 'Exoscale',
        };
    }

    /**
     * How the provider calls a virtual server ("Hetzner Cloud", "DigitalOcean Droplet").
     */
    public function cloudLabel(): string
    {
        return match ($this) {
            self::Hetzner => 'Hetzner Cloud',
            self::DigitalOcean => 'DigitalOcean Droplet',
            self::Aws => 'Amazon EC2',
            self::Gcp => 'Google Compute Engine',
            self::Azure => 'Azure virtual machine',
            self::Linode => 'Akamai Cloud (Linode)',
            self::Vultr => 'Vultr Cloud Compute',
            self::Ovh => 'OVHcloud Public Cloud',
            self::Scaleway => 'Scaleway Instance',
            self::Oracle => 'Oracle Cloud compute',
            self::UpCloud => 'UpCloud server',
            self::Exoscale => 'Exoscale compute',
        };
    }
}
