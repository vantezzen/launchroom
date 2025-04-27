<?php

namespace App\Services;

use App\Models\Environment;
use App\Settings\InstanceSettings;

class DomainValidator
{
    protected InstanceSettings $settings;

    protected string $serverIp;

    public function __construct(InstanceSettings $settings)
    {
        $this->settings = $settings;
        $this->serverIp = baseIp();
    }

    /**
     * Validate all domains for an environment
     */
    public function validateEnvironmentDomains(Environment $environment): array
    {
        $results = [];
        $domains = $environment->domains;

        foreach ($domains as $domain) {
            // Skip validation for sslip.io domains, as they are development domains
            if (str_ends_with($domain, 'sslip.io')) {
                $results[$domain] = [
                    'valid' => true,
                    'is_development' => true,
                    'error' => null,
                ];

                continue;
            }

            $results[$domain] = $this->validateDomain($domain);
        }

        return $results;
    }

    /**
     * Validate a single domain
     */
    public function validateDomain(string $domain): array
    {
        // If server is not publicly accessible, return early
        if (! $this->settings->publicly_accessible && config('app.env') !== 'local') {
            return [
                'valid' => false,
                'is_development' => false,
                'error' => 'server_not_public',
            ];
        }

        try {
            // Use Google's public DNS server to resolve the domain
            $ipAddresses = dns_get_record($domain, DNS_A);

            if (empty($ipAddresses)) {
                return [
                    'valid' => false,
                    'is_development' => false,
                    'error' => 'no_dns_record',
                ];
            }

            $ipMatches = false;
            foreach ($ipAddresses as $record) {
                if (isset($record['ip']) && $record['ip'] === $this->serverIp) {
                    $ipMatches = true;
                    break;
                }
            }

            return [
                'valid' => $ipMatches,
                'is_development' => false,
                'error' => $ipMatches ? null : 'wrong_ip',
                'expected_ip' => $this->serverIp,
                'actual_ips' => array_map(fn ($record) => $record['ip'] ?? null, $ipAddresses),
            ];
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'is_development' => false,
                'error' => 'dns_error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
