<?php

declare(strict_types=1);

namespace App\Enums\Mesh;

/**
 * Secrets of the mesh (`mesh_credentials.purpose`, docs/05 §2).
 */
enum MeshCredentialPurpose: string
{
    /** The dashboard's Headscale API key (instance scope). */
    case HeadscaleApiKey = 'headscale_api_key';

    /** "Bring your own Tailscale": the tailnet auth key (instance scope). */
    case TailscaleAuthKey = 'tailscale_auth_key';

    /** A server's single-use Headscale pre-auth key, until it has joined (server scope). */
    case PreAuthKey = 'pre_auth_key';
}
