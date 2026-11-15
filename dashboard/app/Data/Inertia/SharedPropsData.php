<?php

declare(strict_types=1);

namespace App\Data\Inertia;

use App\Data\Backups\RecoveryKitStatusData;
use App\Data\Instance\OnboardingProgressData;
use App\Data\Instance\RealtimeConfigData;
use App\Data\Servers\ControlPlaneLoadData;
use App\Data\Teams\TeamContextData;
use Spatie\LaravelData\Data;

/**
 * The props every Inertia page gets (HandleInertiaRequests::share), as the TS
 * type of `usePage().props`. The middleware shares most of them as closures so
 * partial reloads skip them; a test checks the shared keys and values match this.
 */
final class SharedPropsData extends Data
{
    public function __construct(
        public string $name,
        public AuthData $auth,
        /** Base URL of the public docs for "Learn more" links (docs/21). */
        public string $docsUrl,
        public bool $sidebarOpen,
        public ?TeamContextData $teams,
        /** The sidebar footer's version, signed-in users only. */
        public ?string $version,
        /** "Save your recovery kit" warning, instance owners only (docs/14 §4). */
        public ?RecoveryKitStatusData $recoveryKit,
        /** After `install.sh --restore` (docs/14 §5.2), instance owners only. */
        public bool $recoveryMode,
        public ?InstanceMoveData $instanceMove,
        /** Reverb client settings (public values only), signed-in users only. */
        public ?RealtimeConfigData $realtime,
        /** "Get started" in the sidebar until the first-run checklist is done. */
        public ?OnboardingProgressData $onboardingProgress,
        /** Heavy load on the control-plane server (docs/08 §4), for people who manage it. */
        public ?ControlPlaneLoadData $controlPlaneLoad,
        /** Open "Action needed" findings of the current team (docs/22 §9.1). */
        public int $securityActionNeeded,
        /** A release is ready (instance owners, cached check only). */
        public bool $updateAvailable,
    ) {}
}
