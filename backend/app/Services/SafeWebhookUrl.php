<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\IpUtils;

class SafeWebhookUrl
{
    public function addresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records ?: [])));
    }

    public function options(string $url): array
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443 || filter_var($host, FILTER_VALIDATE_IP) || ! in_array($host, config('automation.webhook_hosts', []), true) || ! preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host)) {
            throw new ActionFailure('webhook_url_not_allowed');
        }
        $addresses = $this->addresses($host);
        if (! $addresses) {
            throw new ActionFailure('webhook_dns_unavailable', true);
        }
        foreach ($addresses as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || IpUtils::checkIp($ip, ['100.64.0.0/10', '198.18.0.0/15', '224.0.0.0/4']) || (str_contains($ip, ':') && ! IpUtils::checkIp($ip, '2000::/3'))) {
                throw new ActionFailure('webhook_private_address');
            }
        }
        $ip = $addresses[0];
        $pin = str_contains($ip, ':') ? '['.$ip.']' : $ip;

        return ['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$pin]]];
    }
}
