<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Enums\ClientCertificateMode;
use App\Events\NodeStateInvalidated;
use App\Exceptions\DomainRuleViolation;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Queries\Security\ClientCertificatesOverview;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Security → Server certificates (mTLS, D50, docs/03 §4.3):
 * Off → Check → Require. Require is refused while a server isn't ready
 * unless the owner requires anyway ($force, after seeing which servers will
 * be refused). The control-plane server's system routes re-render, so its
 * edge starts (or stops) asking for certificates within seconds.
 */
final readonly class SetClientCertificateMode
{
    public function __construct(
        private ClientCertificatesOverview $overview,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(ClientCertificateMode $mode, AuditActor $actor, bool $force = false): void
    {
        DB::transaction(function () use ($mode, $actor, $force): void {
            $settings = InstanceSettings::lockedForUpdate();
            $from = $settings->daemon_client_certificates ?? ClientCertificateMode::Off;

            if ($from === $mode) {
                return;
            }

            $blocking = $mode === ClientCertificateMode::Require ? $this->overview->blocking() : [];

            if ($mode === ClientCertificateMode::Require && $from === ClientCertificateMode::Off && ! $force) {
                throw DomainRuleViolation::on('mode', 'Turn on Check first, so you can see which servers present their certificate.');
            }

            if ($blocking !== [] && ! $force) {
                throw DomainRuleViolation::on('mode', 'Not every server is ready: '.implode(', ', array_slice($blocking, 0, 5)).(count($blocking) > 5 ? ' and '.(count($blocking) - 5).' more' : '').'.');
            }

            $settings->forceFill([
                'daemon_client_certificates' => $mode,
                'daemon_client_certificates_changed_at' => now(),
            ])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ClientCertificatesChanged,
                actor: $actor,
                meta: ['from' => $from->value, 'to' => $mode->value, 'forced' => $force && $blocking !== [], 'notReady' => count($blocking)],
            ));

            /** @var list<int> $controlPlane */
            $controlPlane = Server::query()->where('is_control_plane', true)->whereNull('revoked_at')->pluck('id')->values()->all();
            event(new NodeStateInvalidated($controlPlane));
        });
    }
}
