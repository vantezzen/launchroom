<?php

declare(strict_types=1);

namespace App\Enums\Servers;

/**
 * The hypervisor or container runtime launchroomd detected (HardwareFacts.virtualization, docs/design/direction.md §7.3).
 */
enum Virtualization: string
{
    case Kvm = 'kvm';
    case Qemu = 'qemu';
    case Vmware = 'vmware';
    case Virtualbox = 'virtualbox';
    case Xen = 'xen';
    case Hyperv = 'hyperv';
    case Parallels = 'parallels';
    case Apple = 'apple';
    case Bhyve = 'bhyve';
    case Container = 'container';
    case Other = 'other';
}
