<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\Instance\System\SystemCheckData;
use App\Enums\Instance\SystemCheckStatus;
use App\Queries\ControlPlaneHealth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The dashboard's own checks for `./launchroom doctor` (G31): migrations,
 * workers, queues, failed jobs, scheduler and its locks, the control-plane
 * backup and recovery kit, this server, disk, other servers, the dashboard
 * certificate and paused changes. `--json` prints
 * `{"checks":[{id, title, status, summary, detail, fix}]}`, the format
 * daemon/internal/doctor reads (it prefixes each id with `dashboard.`).
 * Exit 1 when a check failed.
 */
#[Signature('launchroom:doctor {--json : Print the checks as JSON for ./launchroom doctor}')]
#[Description('Check the control plane: queues, scheduler, backups, migrations and this server')]
final class DoctorCommand extends Command
{
    public function handle(ControlPlaneHealth $health): int
    {
        $checks = $health->checks();
        $failed = array_filter($checks, static fn (SystemCheckData $check): bool => $check->status === SystemCheckStatus::Fail) !== [];

        if ($this->option('json') === true) {
            $this->line((string) json_encode(['checks' => array_map(static fn (SystemCheckData $check): array => [
                'id' => $check->id,
                'title' => $check->title,
                'status' => $check->status->value,
                'summary' => $check->summary,
                'detail' => $check->detail,
                'fix' => $check->fix,
            ], $checks)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        foreach ($checks as $check) {
            $this->line(sprintf('%-5s %-22s %s', strtoupper($check->status->value), $check->title, $check->summary));

            foreach ($check->detail as $line) {
                $this->line('      '.str_repeat(' ', 22).' '.$line);
            }

            if ($check->fix !== null) {
                $this->line('      '.str_repeat(' ', 22).' Fix: ./launchroom doctor --fix '.$check->fix);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
