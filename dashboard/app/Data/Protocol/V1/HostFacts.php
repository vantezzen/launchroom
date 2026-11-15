<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Servers\HostFactsData;
use App\Support\Protocol\Grammar;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

final class HostFacts extends Data
{
    public function __construct(
        #[Regex(Grammar::LABEL)]
        public string $hostname,
        #[Regex(Grammar::LABEL)]
        public string $os,
        #[Regex(Grammar::LABEL)]
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
