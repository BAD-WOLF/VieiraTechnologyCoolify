<?php

namespace App\Services\Platform;

use App\Exceptions\PlatformPolicyViolation;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\ServiceApplication;
use App\Models\Team;

/**
 * Domains are a global namespace on the shared proxies: a host claimed by one team can not be
 * used by any other team (any scheme, port or path), and platform hosts are reserved.
 */
class DomainPolicy
{
    /**
     * @return array<int, string>
     */
    public static function hostsFromFqdnList(?string $fqdns): array
    {
        if (blank($fqdns)) {
            return [];
        }
        $hosts = [];
        foreach (preg_split('/[\s,]+/', (string) $fqdns) ?: [] as $fqdn) {
            $host = self::hostOf($fqdn);
            if ($host) {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Hosts configured for the services of a docker compose based application.
     *
     * @return array<int, string>
     */
    public static function hostsFromComposeDomains(mixed $composeDomains): array
    {
        if (blank($composeDomains)) {
            return [];
        }
        $decoded = is_string($composeDomains) ? json_decode($composeDomains, true) : $composeDomains;
        if (! is_array($decoded)) {
            return [];
        }
        $hosts = [];
        foreach ($decoded as $service) {
            $hosts = array_merge($hosts, self::hostsFromFqdnList((string) data_get($service, 'domain', '')));
        }

        return array_values(array_unique($hosts));
    }

    public static function hostOf(?string $fqdn): ?string
    {
        $fqdn = trim((string) $fqdn);
        if ($fqdn === '') {
            return null;
        }
        if (! str_contains($fqdn, '://')) {
            $fqdn = 'http://'.$fqdn;
        }
        $host = parse_url($fqdn, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return rtrim(strtolower($host), '.');
    }

    /**
     * @return array<int, string>
     */
    public static function hostsOwnedBy(Team|int $team): array
    {
        $teamId = $team instanceof Team ? $team->id : $team;
        $hosts = [];
        $applications = Application::query()
            ->whereHas('environment.project', fn ($query) => $query->where('team_id', $teamId))
            ->get(['id', 'fqdn', 'docker_compose_domains']);
        foreach ($applications as $application) {
            $hosts = array_merge($hosts, self::hostsFromFqdnList($application->fqdn), self::hostsFromComposeDomains($application->docker_compose_domains));
        }
        $previews = ApplicationPreview::query()
            ->whereIn('application_id', $applications->pluck('id'))
            ->get(['fqdn', 'docker_compose_domains']);
        foreach ($previews as $preview) {
            $hosts = array_merge($hosts, self::hostsFromFqdnList($preview->fqdn), self::hostsFromComposeDomains($preview->docker_compose_domains));
        }
        $serviceApplications = ServiceApplication::query()
            ->whereHas('service.environment.project', fn ($query) => $query->where('team_id', $teamId))
            ->get(['fqdn']);
        foreach ($serviceApplications as $serviceApplication) {
            $hosts = array_merge($hosts, self::hostsFromFqdnList($serviceApplication->fqdn));
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @return array<int, string>
     */
    public static function reservedHosts(): array
    {
        $reserved = [];
        $instanceHost = self::hostOf(data_get(instanceSettings(), 'fqdn'));
        if ($instanceHost) {
            $reserved[] = $instanceHost;
        }
        $baseDomain = self::baseDomain();
        if ($baseDomain) {
            $reserved[] = $baseDomain;
        }
        foreach ((array) config('platform.reserved_hosts', []) as $entry) {
            $entry = strtolower(trim((string) $entry));
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, '.')) {
                $reserved[] = $entry;
            } elseif ($baseDomain) {
                $reserved[] = "{$entry}.{$baseDomain}";
            }
        }

        return array_values(array_unique($reserved));
    }

    public static function baseDomain(): ?string
    {
        return self::hostOf((string) config('platform.base_domain'));
    }

    /**
     * Hosts outside the platform base domain (customer domains).
     */
    public static function isCustomHost(string $host): bool
    {
        $baseDomain = self::baseDomain();
        if (! $baseDomain) {
            return true;
        }

        return $host !== $baseDomain && ! str_ends_with($host, '.'.$baseDomain);
    }

    /**
     * Ensure every host is valid, not reserved and not used by another team.
     *
     * @param  array<int, string>  $hosts
     */
    public static function assertAvailable(array $hosts, int $teamId): void
    {
        $violations = [];
        $reserved = self::reservedHosts();
        foreach ($hosts as $host) {
            if (! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)*$/', $host)) {
                $violations[] = "\"{$host}\" is not a valid domain.";

                continue;
            }
            if (in_array($host, $reserved, true)) {
                $violations[] = "The domain \"{$host}\" is reserved by the platform.";

                continue;
            }
            $owner = self::ownerOf($host);
            if (! is_null($owner) && $owner !== $teamId) {
                $violations[] = "The domain \"{$host}\" is already used by another account.";
            }
        }
        if (count($violations) > 0) {
            throw new PlatformPolicyViolation($violations, 'Domain not allowed');
        }
    }

    /**
     * Team id that currently uses a host, or null.
     */
    public static function ownerOf(string $host): ?int
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $host).'%';
        $candidates = Application::query()
            ->withoutGlobalScopes()
            ->where(fn ($query) => $query->where('fqdn', 'like', $like)->orWhere('docker_compose_domains', 'like', $like))
            ->with('environment.project:id,team_id')
            ->get(['id', 'fqdn', 'docker_compose_domains', 'environment_id']);
        foreach ($candidates as $application) {
            $applicationHosts = array_merge(self::hostsFromFqdnList($application->fqdn), self::hostsFromComposeDomains($application->docker_compose_domains));
            if (in_array($host, $applicationHosts, true)) {
                return (int) data_get($application, 'environment.project.team_id');
            }
        }
        $previews = ApplicationPreview::query()
            ->where(fn ($query) => $query->where('fqdn', 'like', $like)->orWhere('docker_compose_domains', 'like', $like))
            ->get(['id', 'fqdn', 'docker_compose_domains', 'application_id']);
        foreach ($previews as $preview) {
            $previewHosts = array_merge(self::hostsFromFqdnList($preview->fqdn), self::hostsFromComposeDomains($preview->docker_compose_domains));
            if (in_array($host, $previewHosts, true)) {
                $application = Application::query()->withoutGlobalScopes()->with('environment.project:id,team_id')->find($preview->application_id);

                return $application ? (int) data_get($application, 'environment.project.team_id') : null;
            }
        }
        $serviceApplications = ServiceApplication::query()
            ->where('fqdn', 'like', $like)
            ->with('service.environment.project:id,team_id')
            ->get(['id', 'fqdn', 'service_id']);
        foreach ($serviceApplications as $serviceApplication) {
            if (in_array($host, self::hostsFromFqdnList($serviceApplication->fqdn), true)) {
                return (int) data_get($serviceApplication, 'service.environment.project.team_id');
            }
        }

        return null;
    }

    /**
     * Number of customer (non-platform) domains used by a team, including the given extra hosts.
     *
     * @param  array<int, string>  $extraHosts
     */
    public static function customHostCount(int $teamId, array $extraHosts = []): int
    {
        $hosts = array_unique(array_merge(self::hostsOwnedBy($teamId), $extraHosts));

        return count(array_filter($hosts, fn ($host) => self::isCustomHost($host)));
    }
}
