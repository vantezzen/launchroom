<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The system value a framework variable is set to (BuildSummary.envAliases[].from,
 * Go `state.EnvAliasSource`, docs/06 §3.1). Resolved per environment at the
 * system layer; workers and crons use their parent app's domains.
 */
enum EnvAliasSource: string
{
    case Domain = 'DOMAIN';
    case Domains = 'DOMAINS';
    case PublicUrl = 'PUBLIC_URL';
    case PublicUrls = 'PUBLIC_URLS';
    case Port = 'PORT';
    /** DOMAINS plus the service's network alias and localhost. */
    case AllowedHostsList = 'ALLOWED_HOSTS_LIST';
    /** The environment network's gateway and the private network's range. */
    case TrustedProxyCidrs = 'TRUSTED_PROXY_CIDRS';
}
