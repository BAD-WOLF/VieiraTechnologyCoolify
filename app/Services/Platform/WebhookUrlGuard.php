<?php

namespace App\Services\Platform;

/**
 * Webhook targets are called from the control plane, so a tenant could point one at the private
 * network, the metadata service or the panel itself (SSRF). Only public addresses are accepted,
 * and the address checked is the one the request goes to (no DNS rebinding, no redirects).
 */
class WebhookUrlGuard
{
    /** @var list<string> */
    private const BLOCKED_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001:db8::/32',
        '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** @var (callable(string): list<string>)|null */
    private static $resolver = null;

    /**
     * Tests: replace the DNS lookup.
     *
     * @param  (callable(string): list<string>)|null  $resolver
     */
    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Error message for an unacceptable URL, or null.
     */
    public static function validate(string $url, bool $allowPrivate = false): ?string
    {
        try {
            self::target($url, $allowPrivate);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Host, port and the vetted address the request must use.
     *
     * @return array{host: string, port: int, ip: string}
     */
    public static function target(string $url, bool $allowPrivate = false): array
    {
        if (strlen($url) > 2048 || preg_match('/[\s\x00-\x1F\x7F]/', $url)) {
            throw new \InvalidArgumentException(__('Invalid webhook URL.'));
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $allowedSchemes = config('platform.webhooks.allow_http') ? ['https', 'http'] : ['https'];
        if (! in_array($scheme, $allowedSchemes, true)) {
            throw new \InvalidArgumentException(__('The webhook URL must use HTTPS.'));
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException(__('The webhook URL can not contain credentials.'));
        }
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '' || $host === 'localhost' || preg_match('/\.(localhost|local|internal|localdomain)$/', $host)) {
            throw new \InvalidArgumentException(__('The webhook URL must point to a public address.'));
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(__('Invalid webhook URL.'));
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if ($addresses === []) {
            throw new \InvalidArgumentException(__('The webhook host could not be resolved.'));
        }
        if (! $allowPrivate) {
            foreach ($addresses as $address) {
                if (! self::isPublicIp($address)) {
                    throw new \InvalidArgumentException(__('The webhook URL must point to a public address.'));
                }
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        foreach (self::BLOCKED_RANGES as $range) {
            if (self::inRange($ip, $range)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        if (self::$resolver) {
            return array_values((array) (self::$resolver)($host));
        }
        $addresses = gethostbynamel($host) ?: [];
        $ipv6 = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($ipv6 as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    private static function inRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);
        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);
        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (strncmp($ipBinary, $subnetBinary, $bytes) !== 0) {
            return false;
        }
        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ipBinary[$bytes]) & $mask) === (ord($subnetBinary[$bytes]) & $mask);
    }
}
