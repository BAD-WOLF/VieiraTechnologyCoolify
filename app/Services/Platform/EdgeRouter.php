<?php

namespace App\Services\Platform;

use App\Models\Application;
use App\Models\PlatformRegion;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use Illuminate\Support\Collection;

/**
 * Dynamic configuration of the regional edge routers (Traefik HTTP provider).
 *
 * Every execution node keeps its own Coolify proxy (labels, TLS and ACME). The edge only knows
 * which node(s) run each public host: HTTP is forwarded with the Host header (ACME HTTP-01
 * challenges included) and HTTPS is passed through by SNI, so one wildcard DNS record and one
 * regional load balancer serve every node of the region.
 */
class EdgeRouter
{
    private const HOST_PATTERN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * @return array{http: array{routers: array<string, mixed>, services: array<string, mixed>}, tcp: array{routers: array<string, mixed>, services: array<string, mixed>}}
     */
    public static function configFor(PlatformRegion $region): array
    {
        $nodes = Server::query()->with('settings')->where('platform_region_id', $region->id)->get()
            ->reject(fn (Server $server) => (bool) $server->settings?->force_disabled)
            ->keyBy('id');

        $config = ['http' => ['routers' => [], 'services' => []], 'tcp' => ['routers' => [], 'services' => []]];
        foreach (self::hostsOn($nodes) as $host => $serverIds) {
            $targets = self::targets($nodes, $serverIds);
            if ($targets->isEmpty()) {
                continue;
            }
            $service = 'nodes-'.$targets->keys()->implode('-');
            if (! isset($config['http']['services'][$service])) {
                $config['http']['services'][$service] = ['loadBalancer' => [
                    'passHostHeader' => true,
                    'servers' => $targets->map(fn (string $address) => ['url' => 'http://'.$address.':'.(int) config('platform.edge.node_http_port', 80)])->values()->all(),
                ]];
                $tcpService = ['servers' => $targets->map(fn (string $address) => ['address' => $address.':'.(int) config('platform.edge.node_https_port', 443)])->values()->all()];
                if (config('platform.edge.proxy_protocol')) {
                    $tcpService['proxyProtocol'] = ['version' => 2];
                }
                $config['tcp']['services'][$service] = ['loadBalancer' => $tcpService];
            }
            $router = 'h'.substr(hash('sha256', $host), 0, 24);
            $config['http']['routers'][$router] = ['entryPoints' => ['web'], 'rule' => "Host(`{$host}`)", 'service' => $service];
            $config['tcp']['routers'][$router] = ['entryPoints' => ['websecure'], 'rule' => "HostSNI(`{$host}`)", 'service' => $service, 'tls' => ['passthrough' => true]];
        }
        ksort($config['http']['routers']);
        ksort($config['tcp']['routers']);

        return $config;
    }

    /**
     * Traefik expects maps, so empty sections are encoded as objects.
     */
    public static function toJson(array $config): array
    {
        foreach (['http', 'tcp'] as $section) {
            foreach (['routers', 'services'] as $key) {
                if (empty($config[$section][$key])) {
                    $config[$section][$key] = new \stdClass;
                }
            }
        }

        return $config;
    }

    /**
     * Public hosts served by the given nodes, with the ids of the nodes running each one.
     *
     * @param  Collection<int, Server>  $nodes
     * @return array<string, array<int, int>>
     */
    public static function hostsOn(Collection $nodes): array
    {
        $nodeIds = $nodes->keys()->all();
        if ($nodeIds === []) {
            return [];
        }
        $destinationServers = StandaloneDocker::query()->whereIn('server_id', $nodeIds)->pluck('server_id', 'id');
        $hosts = [];
        $add = function (array $names, array $serverIds) use (&$hosts) {
            foreach ($names as $name) {
                $name = strtolower(trim((string) $name));
                if (! preg_match(self::HOST_PATTERN, $name)) {
                    continue;
                }
                $hosts[$name] = array_values(array_unique(array_merge($hosts[$name] ?? [], $serverIds)));
            }
        };

        Application::query()
            ->with(['additional_servers', 'previews'])
            ->where(function ($query) use ($destinationServers, $nodeIds) {
                $query->where(fn ($query) => $query->where('destination_type', StandaloneDocker::class)->whereIn('destination_id', $destinationServers->keys()))
                    ->orWhereHas('additional_servers', fn ($query) => $query->whereIn('servers.id', $nodeIds));
            })
            ->chunkById(500, function ($applications) use ($add, $destinationServers, $nodeIds) {
                foreach ($applications as $application) {
                    $serverIds = $application->additional_servers->pluck('id')->intersect($nodeIds)->all();
                    if ($application->destination_type === StandaloneDocker::class && $destinationServers->has($application->destination_id)) {
                        $serverIds[] = (int) $destinationServers->get($application->destination_id);
                    }
                    if ($serverIds === []) {
                        continue;
                    }
                    $add(self::applicationHosts($application->fqdn, $application->docker_compose_domains), $serverIds);
                    foreach ($application->previews as $preview) {
                        $add(self::applicationHosts($preview->fqdn, $preview->docker_compose_domains), $serverIds);
                    }
                }
            });

        Service::query()->with('applications')->whereIn('server_id', $nodeIds)
            ->chunkById(500, function ($services) use ($add) {
                foreach ($services as $service) {
                    foreach ($service->applications as $serviceApplication) {
                        $add(DomainPolicy::hostsFromFqdnList($serviceApplication->fqdn), [(int) $service->server_id]);
                    }
                }
            });

        ksort($hosts);

        return $hosts;
    }

    /**
     * @return array<int, string>
     */
    private static function applicationHosts(?string $fqdn, mixed $composeDomains): array
    {
        return array_merge(DomainPolicy::hostsFromFqdnList($fqdn), DomainPolicy::hostsFromComposeDomains($composeDomains));
    }

    /**
     * Addresses of the nodes running a host. Nodes Coolify can not reach are skipped while
     * another node can serve the host.
     *
     * @param  Collection<int, Server>  $nodes
     * @param  array<int, int>  $serverIds
     * @return Collection<int, string>
     */
    private static function targets(Collection $nodes, array $serverIds): Collection
    {
        $candidates = collect($serverIds)->unique()->sort()->mapWithKeys(fn (int $id) => [$id => $nodes->get($id)])->filter();
        $reachable = $candidates->filter(fn (Server $server) => (bool) $server->settings?->is_reachable);
        $selected = $reachable->isNotEmpty() ? $reachable : $candidates;

        return $selected->map(function (Server $server) {
            $address = trim((string) $server->ip);

            return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$address}]" : $address;
        })->filter(fn (string $address) => $address !== '' && preg_match('/^[A-Za-z0-9.:\[\]\-]+$/', $address));
    }
}
