<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Data\Servers\DmiFactsData;
use App\Data\Servers\HardwareFactsData;
use App\Enums\DeviceKind;
use App\Enums\HostingProvider;

/**
 * Maps a server's hardware facts to a device illustration and a hosting
 * provider (docs/design/direction.md §7.3). Pure and table-driven: the rules
 * run in order and the first match wins.
 *
 * | # | Condition                                                         | Device    |
 * |---|-------------------------------------------------------------------|-----------|
 * | – | no hardware facts                                                 | unknown   |
 * | 0 | the installer named a Mac (`hostModel`, D53): MacBook             | laptop    |
 * |   | Mac Pro                                                           | tower     |
 * |   | any other Mac (mini, Studio, iMac, "Mac14,3")                     | compact   |
 * | 1 | device tree names a single-board computer                         | sbc       |
 * | 2 | DMI vendor is a NAS maker, or the OS/kernel is a NAS system       | nas       |
 * |   | (Synology DSM, QNAP QTS/QuTS, TrueNAS, Unraid), virtualized or not|           |
 * | 3 | Apple Mac mini / Mac Studio (DMI or Asahi device tree)            | compact   |
 * | 4 | virtualized and the provider is known                             | cloud     |
 * | 5 | virtualized (or a container), provider unknown                    | vm        |
 * | 6 | a system battery, a portable chassis (8–11, 14, 30–32), MacBook   | laptop    |
 * | 7 | small chassis (15, 16, 24, 34–36) or a mini PC product name       | minipc    |
 * | 8 | server chassis (17, 23, 25, 28, 29) or a server product line      | dedicated |
 * | 9 | desktop/tower chassis (3, 4, 6, 7, 13)                            | tower     |
 * | 10| not virtualized, nothing else matched                             | dedicated |
 *
 * SMBIOS chassis types: 3 desktop, 4 low-profile desktop, 6 mini tower,
 * 7 tower, 8 portable, 9 laptop, 10 notebook, 11 hand held, 13 all-in-one,
 * 14 sub notebook, 15 space-saving, 16 lunch box, 17 main server chassis,
 * 23 rack mount, 24 sealed-case PC, 25 multi-system, 28 blade, 29 blade
 * enclosure, 30 tablet, 31 convertible, 32 detachable, 34 embedded PC,
 * 35 mini PC, 36 stick PC. 1 (other), 2 (unknown) and 5 (pizza box) say
 * nothing useful and fall through.
 *
 * A NAS system wins over virtualization: TrueNAS or Unraid in a VM is still
 * the NAS the user knows. A battery only counts on bare metal (a VM's
 * passed-through battery says nothing about where it runs).
 *
 * Docker Desktop, OrbStack and Colima on a Mac run launchroomd in a Linux
 * VM, which can't see the machine. server.sh reads the Mac's model on the
 * host (`hostModel`, D53), so such a Mac shows as itself; without it (an
 * older installer) it is `vm` until the user picks the device (maintainer
 * decision 6).
 */
final class DeviceClassifier
{
    private const string SBC = '/raspberry pi|orange pi|\brock(pi| ?\d|chip)|radxa|odroid|jetson|pine ?(64|book|phone)|banana pi|libre computer|nanopi|khadas|beaglebone|starfive|visionfive|milk-?v/i';

    private const string NAS = '/synology|qnap|terramaster|asustor|ugreen nas/i';

    /** NAS operating systems by Docker's OperatingSystem or the kernel release ("6.1.106-Unraid", "6.6.44-production+truenas", "5.10.60-qnap"). */
    private const array NAS_SYSTEMS = [
        ['/truenas/i', 'TrueNAS'],
        ['/unraid/i', 'Unraid'],
        ['/synology|\bdsm\b/i', 'Synology NAS'],
        ['/qnap|\bqu?ts\b/i', 'QNAP NAS'],
    ];

    private const string MINI_PC = '/\bnuc|mini ?pc|\btiny\b|(?<![\w-])micro(?![\w-])|beelink|minisforum|gmktec|\bzotac zbox|brix|chuwi|acemagic|trigkey|geekom|mac ?mini/i';

    private const string SERVER_LINE = '/supermicro|poweredge|proliant|thinksystem|primergy|\bsystem x\b|ucs[ -][bc]\d|gigabyte.*\b(r|g|h)\d{3}\b|asrock ?rack/i';

    /** Placeholder strings firmware vendors leave in DMI fields. */
    private const string JUNK = '/^(to be filled by o\.?e\.?m\.?|system (product name|manufacturer|version)|default string|not (specified|applicable)|none|o\.e\.m\.|oem|n\/a|unknown|type1productconfigid|0+|x+|\.+|standard pc.*)$/i';

    private const array LAPTOP_CHASSIS = [8, 9, 10, 11, 14, 30, 31, 32];

    private const array MINI_PC_CHASSIS = [15, 16, 24, 34, 35, 36];

    private const array SERVER_CHASSIS = [17, 23, 25, 28, 29];

    private const array TOWER_CHASSIS = [3, 4, 6, 7, 13];

    /** DMI vendor/product patterns → provider (first match wins). */
    private const array PROVIDERS = [
        ['/hetzner/i', HostingProvider::Hetzner],
        ['/digitalocean/i', HostingProvider::DigitalOcean],
        ['/amazon ec2|^amazon\b/i', HostingProvider::Aws],
        ['/google( compute engine)?$|^google\b/i', HostingProvider::Gcp],
        ['/linode|akamai/i', HostingProvider::Linode],
        ['/vultr/i', HostingProvider::Vultr],
        ['/\bovh/i', HostingProvider::Ovh],
        ['/scaleway/i', HostingProvider::Scaleway],
        ['/oracle ?cloud|oraclecloud/i', HostingProvider::Oracle],
        ['/upcloud/i', HostingProvider::UpCloud],
        ['/exoscale/i', HostingProvider::Exoscale],
    ];

    public static function classify(?HardwareFactsData $hardware): DeviceDetection
    {
        if (! $hardware instanceof HardwareFactsData) {
            return new DeviceDetection(DeviceKind::Unknown);
        }

        // 0. A Mac the installer named (its engine's VM can't see the machine, D53).
        $mac = self::macFromHostModel($hardware->hostModel);

        if ($mac instanceof DeviceDetection) {
            return $mac;
        }

        $dmi = $hardware->dmi ?? new DmiFactsData;
        $tree = $hardware->deviceTreeModel ?? '';
        $vendor = $dmi->sysVendor ?? '';
        $product = $dmi->productName ?? '';
        $names = implode(' ', array_filter([$product, $dmi->productFamily, $dmi->boardName]));
        $chassis = $dmi->chassisType;
        $provider = self::provider($dmi, $hardware->osName);
        $virtualized = self::virtualized($hardware, $dmi);

        // 1. Single-board computers report a device-tree model.
        if ($tree !== '' && preg_match(self::SBC, $tree) === 1) {
            return new DeviceDetection(DeviceKind::Sbc, self::sbcModel($tree));
        }

        // 2. NAS appliances (the maker's hardware), then NAS systems on any hardware.
        if (preg_match(self::NAS, $vendor.' '.($dmi->boardVendor ?? '')) === 1) {
            return new DeviceDetection(DeviceKind::Nas, self::model($vendor, $product), null, $virtualized, $hardware->virtualization);
        }

        $nasSystem = self::nasSystem($hardware);

        if ($nasSystem !== null) {
            return new DeviceDetection(DeviceKind::Nas, $nasSystem, null, $virtualized, $hardware->virtualization);
        }

        // 3. Mac mini / Mac Studio (Intel Macs via DMI, Apple Silicon via Asahi's device tree).
        $apple = preg_match('/^apple/i', $vendor) === 1;

        if (($apple && preg_match('/^(macmini|mac1[34],|macstudio)/i', $product) === 1)
            || preg_match('/apple mac (mini|studio)/i', $tree) === 1) {
            $model = preg_match('/studio|mac13,|mac14,(13|14)/i', $product.' '.$tree) === 1 ? 'Mac Studio' : 'Mac mini';

            return new DeviceDetection(DeviceKind::Compact, $model);
        }

        // 4./5. Virtual machines: a known provider makes it a cloud server.
        if ($virtualized) {
            if ($provider instanceof HostingProvider) {
                $model = $provider === HostingProvider::Aws && self::clean($product) !== null
                    ? $provider->cloudLabel().' '.$product
                    : $provider->cloudLabel();

                return new DeviceDetection(DeviceKind::Cloud, $model, $provider, true, $hardware->virtualization);
            }

            return new DeviceDetection(DeviceKind::Vm, self::vmModel($hardware, $dmi), null, true, $hardware->virtualization);
        }

        $model = $apple ? self::appleModel($product, $tree) : self::model($vendor, $product);

        // 6. Laptops: a system battery, a portable chassis or a MacBook.
        if ($hardware->battery === true || in_array($chassis, self::LAPTOP_CHASSIS, true) || preg_match('/macbook/i', $product.' '.$tree) === 1) {
            return new DeviceDetection(DeviceKind::Laptop, $model, $provider);
        }

        // 7. Mini PCs.
        if (in_array($chassis, self::MINI_PC_CHASSIS, true) || preg_match(self::MINI_PC, $names.' '.$vendor) === 1) {
            return new DeviceDetection(DeviceKind::Minipc, $model, $provider);
        }

        // 8. Rack servers.
        if (in_array($chassis, self::SERVER_CHASSIS, true) || preg_match(self::SERVER_LINE, $vendor.' '.$names) === 1) {
            return new DeviceDetection(DeviceKind::Dedicated, $model, $provider);
        }

        // 9. Desktops and towers.
        if (in_array($chassis, self::TOWER_CHASSIS, true)) {
            return new DeviceDetection(DeviceKind::Tower, $model, $provider);
        }

        // 10. Bare metal we can't place more precisely (rented dedicated servers mostly).
        return new DeviceDetection(DeviceKind::Dedicated, $model, $provider);
    }

    /** "Mac mini", "MacBook Pro", "iMac", "Mac Pro", "Mac14,3", "MacBookPro18,1" → the device. */
    private static function macFromHostModel(?string $hostModel): ?DeviceDetection
    {
        $model = trim((string) $hostModel);

        if (preg_match('/^(i?mac)/i', $model) !== 1) {
            return null;
        }

        // Model identifiers ("MacBookPro18,1", "Mac14,3") become their family name.
        $label = preg_match('/^[A-Za-z]+\d+,\d+$/', $model) === 1 ? self::appleModel($model, '') : mb_substr($model, 0, 64);

        return match (true) {
            preg_match('/^macbook/i', $model) === 1 => new DeviceDetection(DeviceKind::Laptop, $label),
            preg_match('/^mac ?pro/i', $model) === 1 => new DeviceDetection(DeviceKind::Tower, $label),
            default => new DeviceDetection(DeviceKind::Compact, $label),
        };
    }

    /**
     * The hosting provider named by the DMI fields, if any. Azure shows up as
     * Microsoft's "Virtual Machine" product (a local Hyper-V guest looks the same).
     */
    public static function provider(DmiFactsData $dmi, ?string $osName = null): ?HostingProvider
    {
        $fields = array_values(array_filter([$dmi->sysVendor, $dmi->productName, $dmi->productFamily, $dmi->biosVendor, $dmi->boardVendor]));

        foreach (self::PROVIDERS as [$pattern, $provider]) {
            foreach ($fields as $field) {
                if (preg_match($pattern, $field) === 1) {
                    return $provider;
                }
            }
        }

        if (preg_match('/^microsoft/i', $dmi->sysVendor ?? '') === 1 && preg_match('/^virtual machine$/i', $dmi->productName ?? '') === 1
            && preg_match('/docker desktop|wsl/i', $osName ?? '') !== 1) {
            return HostingProvider::Azure;
        }

        return null;
    }

    /** The NAS operating system named by Docker's OperatingSystem or the kernel release. */
    private static function nasSystem(HardwareFactsData $hardware): ?string
    {
        $hints = trim(($hardware->osName ?? '').' '.($hardware->kernel ?? ''));

        if ($hints === '') {
            return null;
        }

        foreach (self::NAS_SYSTEMS as [$pattern, $name]) {
            if (preg_match($pattern, $hints) === 1) {
                return $name;
            }
        }

        return null;
    }

    private static function virtualized(HardwareFactsData $hardware, DmiFactsData $dmi): bool
    {
        if ($hardware->virtualization !== null) {
            return true;
        }

        // Older launchroomd or a hypervisor without the CPU flag: DMI still tells.
        return preg_match('/^(qemu|kvm|vmware|innotek|virtualbox|parallels|xen|bochs|bhyve|openstack)/i', ($dmi->sysVendor ?? '').' ') === 1
            || preg_match('/(virtual ?machine|kvm|vmware|virtualbox|hvm domu|openstack nova)/i', $dmi->productName ?? '') === 1
            || preg_match('/docker desktop|orbstack/i', $hardware->osName ?? '') === 1;
    }

    private static function vmModel(HardwareFactsData $hardware, DmiFactsData $dmi): string
    {
        $os = $hardware->osName ?? '';

        return match (true) {
            preg_match('/docker desktop/i', $os) === 1 => 'Docker Desktop VM',
            preg_match('/orbstack/i', $os) === 1 => 'OrbStack VM',
            $hardware->virtualization === 'container' => 'Container',
            $hardware->virtualization === 'virtualbox' || preg_match('/virtualbox|innotek/i', ($dmi->sysVendor ?? '').($dmi->productName ?? '')) === 1 => 'VirtualBox VM',
            $hardware->virtualization === 'vmware' => 'VMware VM',
            $hardware->virtualization === 'parallels' => 'Parallels VM',
            $hardware->virtualization === 'apple' => 'Apple Virtualization VM',
            $hardware->virtualization === 'hyperv' => 'Hyper-V VM',
            preg_match('/proxmox/i', ($dmi->sysVendor ?? '').' '.($dmi->productName ?? '')) === 1 => 'Proxmox VM',
            default => 'Virtual machine',
        };
    }

    private static function sbcModel(string $tree): string
    {
        // "Raspberry Pi 5 Model B Rev 1.0" → "Raspberry Pi 5"; "Raspberry Pi 4 Model B" → "Raspberry Pi 4".
        if (preg_match('/^(raspberry pi (?:compute module \d+|\d+|zero[ 0-9w]*?|model [ab]\+?))\b/i', $tree, $m) === 1) {
            return trim($m[1]);
        }

        return mb_substr(trim((string) preg_replace('/\s+rev(ision)?\s+[\w.]+$/i', '', $tree)), 0, 64);
    }

    private static function appleModel(string $product, string $tree): string
    {
        if (preg_match('/^apple (mac[\w ]*?)( \(|$)/i', $tree, $m) === 1) {
            return $m[1];
        }

        return match (true) {
            preg_match('/macbookpro/i', $product) === 1 => 'MacBook Pro',
            preg_match('/macbookair/i', $product) === 1 => 'MacBook Air',
            preg_match('/imac/i', $product) === 1 => 'iMac',
            preg_match('/macpro/i', $product) === 1 => 'Mac Pro',
            default => 'Mac',
        };
    }

    /**
     * "LENOVO 20XW0026GE" style names, without firmware placeholders; vendor
     * repeated in the product is dropped ("Intel Corporation NUC8i5BEH" → "Intel NUC8i5BEH").
     */
    private static function model(string $vendor, string $product): ?string
    {
        $vendor = self::clean((string) preg_replace('/[\s,]*(corporation|corp\.?|inc\.?|co\.,? ?ltd\.?|ltd\.?|gmbh|technology|computer|international|& co\. kg)$/i', '', $vendor));
        $product = self::clean($product);

        if ($product === null) {
            return $vendor;
        }

        if ($vendor === null || stripos($product, $vendor) === 0) {
            return mb_substr($product, 0, 64);
        }

        return mb_substr($vendor.' '.$product, 0, 64);
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || preg_match(self::JUNK, $value) === 1 ? null : $value;
    }
}
