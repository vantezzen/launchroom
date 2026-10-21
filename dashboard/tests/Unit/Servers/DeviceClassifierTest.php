<?php

declare(strict_types=1);

use App\Data\Protocol\V1\HardwareFacts;
use App\Data\Servers\DmiFactsData;
use App\Data\Servers\HardwareFactsData;
use App\Enums\DeviceKind;
use App\Enums\HostingProvider;
use App\Support\Servers\DeviceClassifier;

/*
| Fixture facts for every row of docs/design/direction.md §7.3, as launchroomd
| reports them (HardwareFacts). DMI strings are what these machines really
| show in /sys/class/dmi/id.
*/

function facts(?array $dmi = null, ?string $tree = null, ?string $virt = null, ?string $os = null, ?string $cpu = null, ?string $kernel = null, ?bool $battery = null, ?string $hostModel = null): HardwareFactsData
{
    return new HardwareFactsData(
        cpuModel: $cpu,
        dmi: $dmi === null ? null : new DmiFactsData(...$dmi),
        deviceTreeModel: $tree,
        virtualization: $virt,
        osName: $os,
        kernel: $kernel,
        battery: $battery,
        hostModel: $hostModel,
    );
}

dataset('devices', [
    'Hetzner Cloud VM' => [facts(['sysVendor' => 'Hetzner', 'productName' => 'vServer', 'productFamily' => 'Hetzner_vServer', 'biosVendor' => 'Hetzner', 'chassisType' => 1], virt: 'kvm'), DeviceKind::Cloud, HostingProvider::Hetzner, 'Hetzner Cloud'],
    'DigitalOcean droplet' => [facts(['sysVendor' => 'DigitalOcean', 'productName' => 'Droplet', 'productFamily' => 'DigitalOcean_Droplet', 'biosVendor' => 'DigitalOcean', 'chassisType' => 1], virt: 'kvm'), DeviceKind::Cloud, HostingProvider::DigitalOcean, 'DigitalOcean Droplet'],
    'AWS EC2 (Nitro)' => [facts(['sysVendor' => 'Amazon EC2', 'productName' => 't3.medium', 'biosVendor' => 'Amazon EC2', 'chassisType' => 1], virt: 'kvm'), DeviceKind::Cloud, HostingProvider::Aws, 'Amazon EC2 t3.medium'],
    'AWS EC2 bare metal' => [facts(['sysVendor' => 'Amazon EC2', 'productName' => 'm5.metal', 'biosVendor' => 'Amazon EC2', 'chassisType' => 23]), DeviceKind::Dedicated, HostingProvider::Aws, 'Amazon EC2 m5.metal'],
    'Google Compute Engine' => [facts(['sysVendor' => 'Google', 'productName' => 'Google Compute Engine', 'biosVendor' => 'Google', 'chassisType' => 1], virt: 'kvm'), DeviceKind::Cloud, HostingProvider::Gcp, 'Google Compute Engine'],
    'Azure VM' => [facts(['sysVendor' => 'Microsoft Corporation', 'productName' => 'Virtual Machine', 'chassisType' => 3], virt: 'hyperv'), DeviceKind::Cloud, HostingProvider::Azure, 'Azure virtual machine'],
    'bare-metal Supermicro' => [facts(['sysVendor' => 'Supermicro', 'productName' => 'SYS-5019C-MR', 'boardVendor' => 'Supermicro', 'boardName' => 'X11SCM-F', 'chassisType' => 17]), DeviceKind::Dedicated, null, 'Supermicro SYS-5019C-MR'],
    'Dell PowerEdge (desktop chassis code)' => [facts(['sysVendor' => 'Dell Inc.', 'productName' => 'PowerEdge T140', 'chassisType' => 7]), DeviceKind::Dedicated, null, 'Dell PowerEdge T140'],
    'Intel NUC' => [facts(['sysVendor' => 'Intel(R) Client Systems', 'productName' => 'NUC8i5BEH', 'boardVendor' => 'Intel Corporation', 'boardName' => 'NUC8BEB', 'chassisType' => 3]), DeviceKind::Minipc, null, 'Intel(R) Client Systems NUC8i5BEH'],
    'Lenovo ThinkCentre Tiny' => [facts(['sysVendor' => 'LENOVO', 'productName' => '11A4S0BU00', 'productFamily' => 'ThinkCentre M920q Tiny', 'chassisType' => 35]), DeviceKind::Minipc, null, 'LENOVO 11A4S0BU00'],
    'Raspberry Pi 5' => [facts(tree: 'Raspberry Pi 5 Model B Rev 1.0', cpu: 'Cortex-A76'), DeviceKind::Sbc, null, 'Raspberry Pi 5'],
    'Orange Pi' => [facts(tree: 'Orange Pi 5 Plus'), DeviceKind::Sbc, null, 'Orange Pi 5 Plus'],
    'Mac mini on Asahi' => [facts(tree: 'Apple Mac mini (M2, 2023)'), DeviceKind::Compact, null, 'Mac mini'],
    'Intel Mac mini' => [facts(['sysVendor' => 'Apple Inc.', 'productName' => 'Macmini8,1', 'chassisType' => 3]), DeviceKind::Compact, null, 'Mac mini'],
    'Docker Desktop VM' => [facts(virt: 'apple', os: 'Docker Desktop'), DeviceKind::Vm, null, 'Docker Desktop VM'],
    // Macs the installer named (D53): the engine's VM can't see the machine.
    'Mac mini on OrbStack' => [facts(virt: 'apple', os: 'OrbStack', hostModel: 'Mac mini'), DeviceKind::Compact, null, 'Mac mini'],
    'Mac Studio on Docker Desktop' => [facts(virt: 'apple', os: 'Docker Desktop', hostModel: 'Mac Studio'), DeviceKind::Compact, null, 'Mac Studio'],
    'MacBook Pro on Colima' => [facts(['sysVendor' => 'QEMU', 'productName' => 'Standard PC (Q35 + ICH9, 2009)'], virt: 'qemu', os: 'Ubuntu 24.04.2 LTS', hostModel: 'MacBook Pro'), DeviceKind::Laptop, null, 'MacBook Pro'],
    'iMac' => [facts(virt: 'apple', os: 'OrbStack', hostModel: 'iMac'), DeviceKind::Compact, null, 'iMac'],
    'Mac Pro' => [facts(virt: 'apple', os: 'Docker Desktop', hostModel: 'Mac Pro'), DeviceKind::Tower, null, 'Mac Pro'],
    'Mac by model identifier' => [facts(virt: 'apple', os: 'OrbStack', hostModel: 'Mac14,3'), DeviceKind::Compact, null, 'Mac'],
    'MacBook by model identifier' => [facts(virt: 'apple', os: 'OrbStack', hostModel: 'MacBookAir10,1'), DeviceKind::Laptop, null, 'MacBook Air'],
    'a host model that is no Mac is ignored' => [facts(virt: 'apple', os: 'Docker Desktop', hostModel: 'ThinkPad'), DeviceKind::Vm, null, 'Docker Desktop VM'],
    'Docker Desktop without hypervisor hints' => [facts(os: 'Docker Desktop'), DeviceKind::Vm, null, 'Docker Desktop VM'],
    'VirtualBox guest' => [facts(['sysVendor' => 'innotek GmbH', 'productName' => 'VirtualBox', 'chassisType' => 1], virt: 'virtualbox'), DeviceKind::Vm, null, 'VirtualBox VM'],
    'Proxmox guest (QEMU)' => [facts(['sysVendor' => 'QEMU', 'productName' => 'Standard PC (Q35 + ICH9, 2009)', 'chassisType' => 1], virt: 'kvm'), DeviceKind::Vm, null, 'Virtual machine'],
    'Synology' => [facts(['sysVendor' => 'Synology Inc.', 'productName' => 'DS920+']), DeviceKind::Nas, null, 'Synology DS920+'],
    'laptop chassis' => [facts(['sysVendor' => 'LENOVO', 'productName' => '20XW0026GE', 'productFamily' => 'ThinkPad X1 Carbon Gen 9', 'chassisType' => 10]), DeviceKind::Laptop, null, 'LENOVO 20XW0026GE'],
    'tower' => [facts(['sysVendor' => 'To Be Filled By O.E.M.', 'productName' => 'To Be Filled By O.E.M.', 'boardVendor' => 'ASRock', 'boardName' => 'B550M Pro4', 'chassisType' => 3]), DeviceKind::Tower, null, null],
    'dind dev server' => [facts(virt: 'container', os: 'Alpine Linux v3.22'), DeviceKind::Vm, null, 'Container'],
    'bare metal without DMI hints' => [facts(['sysVendor' => 'FUJITSU', 'productName' => 'D3417-B1', 'chassisType' => 2]), DeviceKind::Dedicated, null, 'FUJITSU D3417-B1'],
    // Laptops: a system battery wins over a missing or misleading chassis code.
    'laptop by battery (junk DMI)' => [facts(['sysVendor' => 'To Be Filled By O.E.M.', 'productName' => 'To Be Filled By O.E.M.', 'chassisType' => 3], battery: true), DeviceKind::Laptop, null, null],
    'laptop by battery (no DMI)' => [facts(os: 'Ubuntu 24.04.1 LTS', battery: true), DeviceKind::Laptop, null, null],
    'convertible' => [facts(['sysVendor' => 'HP', 'productName' => 'HP Spectre x360 Convertible 14', 'chassisType' => 31]), DeviceKind::Laptop, null, 'HP Spectre x360 Convertible 14'],
    'tablet chassis' => [facts(['sysVendor' => 'Microsoft Corporation', 'productName' => 'Surface Pro 9', 'chassisType' => 30]), DeviceKind::Laptop, null, 'Microsoft Surface Pro 9'],
    'battery in a VM stays a VM' => [facts(['sysVendor' => 'QEMU', 'productName' => 'Standard PC (Q35 + ICH9, 2009)', 'chassisType' => 1], virt: 'kvm', battery: true), DeviceKind::Vm, null, 'Virtual machine'],
    // Towers and desktops.
    'Dell OptiPlex tower' => [facts(['sysVendor' => 'Dell Inc.', 'productName' => 'OptiPlex 7090', 'chassisType' => 7]), DeviceKind::Tower, null, 'Dell OptiPlex 7090'],
    'mini tower' => [facts(['sysVendor' => 'HP', 'productName' => 'HP Pro Tower 400 G9', 'chassisType' => 6]), DeviceKind::Tower, null, 'HP Pro Tower 400 G9'],
    'all-in-one' => [facts(['sysVendor' => 'LENOVO', 'productName' => '11T4S00K00', 'chassisType' => 13]), DeviceKind::Tower, null, 'LENOVO 11T4S00K00'],
    'small form factor desktop' => [facts(['sysVendor' => 'HP', 'productName' => 'HP EliteDesk 800 G4 SFF', 'chassisType' => 15]), DeviceKind::Minipc, null, 'HP EliteDesk 800 G4 SFF'],
    'blade' => [facts(['sysVendor' => 'Cisco Systems Inc', 'productName' => 'UCSB-B200-M5', 'chassisType' => 28]), DeviceKind::Dedicated, null, 'Cisco Systems UCSB-B200-M5'],
    // NAS: the maker's hardware or a NAS system on any hardware.
    'QNAP' => [facts(['sysVendor' => 'QNAP Systems, Inc.', 'productName' => 'TS-464']), DeviceKind::Nas, null, 'QNAP Systems TS-464'],
    'TrueNAS SCALE on a DIY board' => [facts(['sysVendor' => 'ASRock', 'productName' => 'B550M Pro4', 'chassisType' => 3], os: 'Debian GNU/Linux 12 (bookworm)', kernel: '6.6.44-production+truenas'), DeviceKind::Nas, null, 'TrueNAS'],
    'TrueNAS in a VM' => [facts(['sysVendor' => 'QEMU', 'productName' => 'Standard PC (Q35 + ICH9, 2009)'], virt: 'kvm', kernel: '6.6.44-production+truenas'), DeviceKind::Nas, null, 'TrueNAS'],
    'Unraid' => [facts(['sysVendor' => 'Micro-Star International Co., Ltd.', 'productName' => 'MS-7D25', 'chassisType' => 3], os: 'Slackware 15.0 x86_64 (post 15.0 -current)', kernel: '6.1.106-Unraid'), DeviceKind::Nas, null, 'Unraid'],
    'Synology DSM (no DMI)' => [facts(os: 'Synology DSM 7.2', kernel: '4.4.302+'), DeviceKind::Nas, null, 'Synology NAS'],
    'QNAP QuTS hero' => [facts(os: 'QuTS hero h5.1.0', kernel: '5.10.60-qnap'), DeviceKind::Nas, null, 'QNAP NAS'],
    'MSI desktop (Micro-Star is not a mini PC)' => [facts(['sysVendor' => 'Micro-Star International Co., Ltd.', 'productName' => 'MS-7C02', 'chassisType' => 3]), DeviceKind::Tower, null, 'Micro-Star International MS-7C02'],
]);

test('hardware facts map to a device and provider', function (HardwareFactsData $facts, DeviceKind $kind, ?HostingProvider $provider, ?string $model): void {
    $detection = DeviceClassifier::classify($facts);

    expect($detection->kind)->toBe($kind)
        ->and($detection->provider)->toBe($provider)
        ->and($detection->model)->toBe($model);
})->with('devices');

test('a server without hardware facts is unknown', function (): void {
    expect(DeviceClassifier::classify(null)->kind)->toBe(DeviceKind::Unknown);
});

test('virtualization is inferred from DMI when launchroomd reports none', function (): void {
    expect(DeviceClassifier::classify(facts(['sysVendor' => 'QEMU', 'productName' => 'Standard PC (i440FX + PIIX, 1996)']))->kind)->toBe(DeviceKind::Vm)
        ->and(DeviceClassifier::classify(facts(['sysVendor' => 'Hetzner', 'productName' => 'vServer']))->kind)->toBe(DeviceKind::Dedicated)
        ->and(DeviceClassifier::classify(facts(['sysVendor' => 'VMware, Inc.', 'productName' => 'VMware Virtual Platform']))->kind)->toBe(DeviceKind::Vm);
});

test('the battery fact passes the protocol whitelist and makes a laptop', function (): void {
    $stored = HardwareFacts::validateAndCreate(['battery' => true, 'dmi' => ['sysVendor' => 'Framework', 'productName' => 'Laptop 13 (AMD Ryzen 7040Series)']])->toStored();

    expect($stored->battery)->toBeTrue()
        ->and(DeviceClassifier::classify($stored)->kind)->toBe(DeviceKind::Laptop)
        ->and(HardwareFacts::validateAndCreate(['battery' => false])->toStored()->battery)->toBeNull();
});
