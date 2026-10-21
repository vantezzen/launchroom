<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The secrets in `ingress_credentials` (docs/05 §5, docs/09 R13).
 */
enum IngressCredentialPurpose: string
{
    /** The DNS provider API token (DNS-01). */
    case DnsToken = 'dns_token';
    /** The key that encrypts certificates in the shared certificate storage. */
    case StorageKey = 'storage_key';
    /** The password of the `lr-caddy` Valkey ACL user (automatic shared storage). */
    case StoragePassword = 'storage_password';
}
