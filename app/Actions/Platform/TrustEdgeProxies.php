<?php

namespace App\Actions\Platform;

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Actions\Proxy\StartProxy;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

/**
 * The edge routers of a region send the address of the visitor (X-Forwarded-For on HTTP, PROXY
 * protocol on HTTPS). The Traefik of each node only believes them when they come from the
 * network of the region, so that network is added to its entrypoints and the proxy restarted.
 * Done by the region assignment of Ansible (docs/region-onboarding.md); nothing to edit by hand.
 */
class TrustEdgeProxies
{
    use AsAction;

    public string $jobQueue = 'high';

    public const FORWARDED_HEADERS = '--entrypoints.http.forwardedheaders.trustedips=';

    public const PROXY_PROTOCOL = '--entrypoints.https.proxyprotocol.trustedips=';

    /**
     * @param  array<int, string>  $cidrs
     * @return bool whether the proxy configuration changed
     */
    public function handle(Server $server, array $cidrs): bool
    {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            return false;
        }
        $configuration = GetProxyConfiguration::run($server);
        $updated = self::withTrustedProxies($configuration, $cidrs);
        if (is_null($updated)) {
            return false;
        }
        SaveProxyConfiguration::run($server, $updated);
        StartProxy::run($server, async: false, force: true);
        audit_log('platform.node.edge_trusted', $server, ['cidrs' => array_values($cidrs)], 0, 'system');

        return true;
    }

    /**
     * Proxy configuration trusting the given networks, or null when it already does (or is not a
     * Traefik configuration).
     *
     * @param  array<int, string>  $cidrs
     */
    public static function withTrustedProxies(string $configuration, array $cidrs): ?string
    {
        try {
            $compose = Yaml::parse($configuration);
        } catch (\Throwable) {
            return null;
        }
        $command = data_get($compose, 'services.traefik.command');
        if (! is_array($command)) {
            return null;
        }
        $kept = array_values(array_filter($command, fn ($flag) => ! str_starts_with(strtolower((string) $flag), self::FORWARDED_HEADERS)
            && ! str_starts_with(strtolower((string) $flag), self::PROXY_PROTOCOL)));
        $cidrs = array_values(array_unique(array_filter(array_map('trim', $cidrs))));
        if ($cidrs !== []) {
            $list = implode(',', $cidrs);
            $kept[] = self::FORWARDED_HEADERS.$list;
            $kept[] = self::PROXY_PROTOCOL.$list;
        }
        if ($kept === array_values($command)) {
            return null;
        }
        data_set($compose, 'services.traefik.command', $kept);

        return Yaml::dump($compose, 12, 2);
    }

    public static function isCidr(string $value): bool
    {
        if (! str_contains($value, '/')) {
            return false;
        }
        [$address, $prefix] = explode('/', $value, 2);
        if (! ctype_digit($prefix)) {
            return false;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (int) $prefix <= 32;
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && (int) $prefix <= 128;
    }
}
