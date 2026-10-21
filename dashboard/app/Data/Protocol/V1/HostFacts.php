<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Servers\HostFactsData;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

final class HostFacts extends Data
{
    private const string LABEL = '/^[A-Za-z0-9._-]{1,128}$/D';

    public function __construct(
        #[Regex(self::LABEL)]
        public string $hostname,
        #[Regex(self::LABEL)]
        public string $os,
        #[Regex(self::LABEL)]
        public string $arch,
        #[Min(1), Max(4096)]
        public int $cpus,
        #[Min(0)]
        public int|Optional $memoryMb = new Optional,
        public HardwareFacts|null|Optional $hardware = new Optional,
    ) {}

    public function toStored(): HostFactsData
    {
        return new HostFactsData(
            $this->hostname,
            $this->os,
            $this->arch,
            $this->cpus,
            $this->memoryMb instanceof Optional ? null : $this->memoryMb,
            $this->hardware instanceof HardwareFacts ? $this->hardware->toStored() : null,
        );
    }
}
