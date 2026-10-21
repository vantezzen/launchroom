<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Servers\DmiFactsData;
use App\Data\Servers\HardwareFactsData;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * EnrollRequest.host.hardware and Report.host.hardware
 * (protocol/schema/v1/report.json `HardwareFacts`): what launchroomd read
 * about the machine (docs/design/direction.md §7.3). Display and device
 * detection only; never identifiers.
 */
final class HardwareFacts extends Data
{
    /** Printable text without control characters, 1–128 characters (FactText). */
    public const string TEXT = '/^[^\x00-\x1f\x7f]{1,128}$/Du';

    public const string TIMEZONE = '/^[A-Za-z][A-Za-z0-9_+-]{0,31}(\/[A-Za-z0-9_+-]{1,32}){0,2}$/D';

    public const array VIRTUALIZATIONS = ['kvm', 'qemu', 'vmware', 'virtualbox', 'xen', 'hyperv', 'parallels', 'apple', 'bhyve', 'container', 'other'];

    /**
     * @param  array<string, mixed>|null|Optional  $dmi  validated by rules(), normalized by toStored()
     */
    public function __construct(
        #[Regex(self::TEXT)]
        public string|Optional $cpuModel = new Optional,
        #[Regex(self::TEXT)]
        public string|Optional $cpuVendor = new Optional,
        public array|null|Optional $dmi = new Optional,
        #[Regex(self::TEXT)]
        public string|Optional $deviceTreeModel = new Optional,
        #[In(self::VIRTUALIZATIONS)]
        public string|Optional $virtualization = new Optional,
        #[Regex(self::TEXT)]
        public string|Optional $osName = new Optional,
        #[Regex(self::TEXT)]
        public string|Optional $kernel = new Optional,
        #[Regex(self::TIMEZONE)]
        public string|Optional $timezone = new Optional,
        /** A system battery (power supply of type Battery, scope not Device). */
        public bool|Optional $battery = new Optional,
        /** The model the installer read on the host, e.g. "Mac mini" (macOS hides it from the VM, D53). */
        #[Regex(self::TEXT)]
        public string|Optional $hostModel = new Optional,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $text = ['sometimes', 'string', 'max:128', 'regex:'.self::TEXT];

        return [
            'dmi' => ['nullable', 'array'],
            'dmi.sysVendor' => $text,
            'dmi.productName' => $text,
            'dmi.productFamily' => $text,
            'dmi.boardVendor' => $text,
            'dmi.boardName' => $text,
            'dmi.biosVendor' => $text,
            'dmi.chassisType' => ['sometimes', 'integer', 'min:0', 'max:255'],
        ];
    }

    /**
     * Whitelisted copy for `servers.facts.hardware`.
     */
    public function toStored(): HardwareFactsData
    {
        $dmi = is_array($this->dmi) ? $this->dmi : null;
        $text = static fn (string $key): ?string => is_string($dmi[$key] ?? null) ? $dmi[$key] : null;

        return new HardwareFactsData(
            cpuModel: $this->value($this->cpuModel),
            cpuVendor: $this->value($this->cpuVendor),
            dmi: $dmi === null ? null : new DmiFactsData(
                sysVendor: $text('sysVendor'),
                productName: $text('productName'),
                productFamily: $text('productFamily'),
                boardVendor: $text('boardVendor'),
                boardName: $text('boardName'),
                biosVendor: $text('biosVendor'),
                chassisType: is_int($dmi['chassisType'] ?? null) ? $dmi['chassisType'] : null,
            ),
            deviceTreeModel: $this->value($this->deviceTreeModel),
            virtualization: $this->value($this->virtualization),
            osName: $this->value($this->osName),
            kernel: $this->value($this->kernel),
            timezone: $this->value($this->timezone),
            battery: $this->battery === true ? true : null,
            hostModel: $this->value($this->hostModel),
        );
    }

    private function value(string|Optional $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
