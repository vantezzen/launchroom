<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Facades\DB;

/**
 * Postgres advisory lock keys (bigint), one place so no two features pick
 * the same number. Keys built from text (`hashtext(...)`) are int4 and can't
 * collide with these.
 */
enum AdvisoryLock: string
{
    /** One writer appends to the audit hash chain. */
    case AuditChain = 'audit_chain';

    /** The client-certificate CA is created once. */
    case ClientCertificateAuthority = 'client_ca';

    /** Mesh link port allocation, one global order. */
    case MeshPorts = 'mesh_ports';

    case EnvironmentSubnets = 'subnets';

    /**
     * The bigint key. Existing keys are kept so a rolling update never runs
     * two lock owners with different keys at once.
     */
    public function key(): int
    {
        return match ($this) {
            self::AuditChain => 0x6C72_6175_6469_74, // "lraudit"
            self::ClientCertificateAuthority => 0x6C72_6361_0001, // "lrca" 1
            self::MeshPorts => 0x6C724D50, // "lrMP"
            self::EnvironmentSubnets => 0x6C72_7375_626E_6574, // "lrsubnet"
        };
    }

    public function holdForTransaction(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [$this->key()]);
    }
}
