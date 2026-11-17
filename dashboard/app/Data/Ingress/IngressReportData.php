<?php

declare(strict_types=1);

namespace App\Data\Ingress;

use App\Data\Instance\DashboardCertificateData;
use App\Data\Protocol\V1\CertStorageStatus;
use App\Data\Protocol\V1\IngressStatus;
use App\Data\Protocol\V1\LogLine;
use App\Enums\IngressFailureReason;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * `servers.ingress_status`: the summary of the edge's latest Report.ingress
 * (App\Actions\Servers\RecordCertificates). When Caddy isn't serving, why
 * (`reason`, the `port` and the `process` holding it, Caddy's last lines);
 * `failingSince` is when it stopped being `applied` (kept across reports,
 * for the `ingress_down` alert after 2 minutes).
 */
final class IngressReportData extends Data
{
    public const int MAX_OUTPUT_LINES = 30;

    /**
     * @param  list<string>  $output  Caddy's last lines (text only)
     */
    public function __construct(
        /** `pending`, `applied` or `failed`. */
        public string $state,
        public int $routes,
        public ?CertStorageReportData $storage = null,
        public ?string $message = null,
        public ?IngressFailureReason $reason = null,
        public ?int $port = null,
        public ?string $process = null,
        public array $output = [],
        public ?string $failingSince = null,
        /** The control plane: the certificate of the dashboard's own domain (RecordCertificates). */
        public ?DashboardCertificateData $dashboardCertificate = null,
        public ?string $dashboardHost = null,
    ) {}

    public static function fromProtocol(IngressStatus $ingress, ?self $previous = null, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();
        $storage = $ingress->storage instanceof CertStorageStatus ? $ingress->storage : null;
        $failing = $ingress->state !== 'applied';
        $output = [];

        /** @var list<mixed> $lines */
        $lines = is_array($ingress->output) ? $ingress->output : [];

        foreach ($lines as $line) {
            $text = match (true) {
                $line instanceof LogLine => $line->text,
                is_array($line) && is_string($line['text'] ?? null) => $line['text'],
                default => null,
            };

            if ($text !== null) {
                $output[] = mb_substr($text, 0, 2048);
            }
        }

        return new self(
            state: $ingress->state,
            routes: $ingress->routes,
            storage: $storage instanceof CertStorageStatus ? new CertStorageReportData(
                $storage->state,
                $storage->mirrored,
                $storage->message instanceof Optional ? null : mb_substr($storage->message, 0, 1024),
            ) : null,
            message: is_string($ingress->message) && $ingress->message !== '' ? mb_substr($ingress->message, 0, 4096) : null,
            reason: $ingress->reason instanceof IngressFailureReason ? $ingress->reason : null,
            port: is_int($ingress->port) ? $ingress->port : null,
            process: is_string($ingress->process) && $ingress->process !== '' ? mb_substr($ingress->process, 0, 64) : null,
            output: array_slice($output, -self::MAX_OUTPUT_LINES),
            failingSince: $failing ? ($previous->failingSince ?? $now->toIso8601ZuluString()) : null,
        );
    }

    /**
     * Why the edge isn't serving, in product words (server page, alert):
     * what happened and what to do. Null while it serves.
     *
     * @return array{title: string, detail: string}|null
     */
    public function problem(): ?array
    {
        if ($this->state === 'applied') {
            return null;
        }

        $port = $this->port ?? 80;

        return match ($this->reason) {
            IngressFailureReason::PortInUse => [
                'title' => "Port {$port} is taken by ".($this->process ?? 'another program'),
                'detail' => $this->process !== null
                    ? "The web server can’t listen on port {$port} because {$this->process} is using it. Stop {$this->process} on that machine, for example with `sudo systemctl disable --now {$this->process}`. launchroom tries again by itself."
                    : "The web server can’t listen on port {$port} because another program is using it. `sudo ss -ltnp 'sport = :{$port}'` on that machine shows which one. Stop it, and launchroom tries again by itself.",
            ],
            IngressFailureReason::ConfigRefused => [
                'title' => 'The new routes were refused',
                'detail' => 'The web server refused the latest routes and keeps serving the ones before. New domains and changes wait until this is fixed. Its last lines are below.',
            ],
            IngressFailureReason::Image => [
                'title' => 'The web server couldn’t be downloaded',
                'detail' => 'launchroom couldn’t download the web server for this server. Check that the machine reaches the internet. It tries again by itself.',
            ],
            IngressFailureReason::NotRunning => [
                'title' => 'The web server keeps stopping',
                'detail' => 'The web server on this server doesn’t stay up, so your domains aren’t served from here. Its last lines are below.',
            ],
            default => $this->state === 'pending'
                ? ['title' => 'Applying routes', 'detail' => 'The web server is loading the latest routes.']
                : ['title' => 'Routes not applied', 'detail' => $this->message ?? 'The web server isn’t serving the latest routes.'],
        };
    }

    /** Not serving its routes for at least `$minutes`. */
    public function downFor(int $minutes, CarbonImmutable $now): bool
    {
        return $this->failingSince !== null && CarbonImmutable::parse($this->failingSince)->lessThanOrEqualTo($now->subMinutes($minutes));
    }
}
