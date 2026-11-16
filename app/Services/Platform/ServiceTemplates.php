<?php

namespace App\Services\Platform;

use App\Exceptions\PlatformPolicyViolation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\Yaml\Yaml;

/**
 * One-click service templates as platform tenants get them.
 *
 * Upstream templates are written for a server the user owns. The platform offers the ones it can
 * run isolated on a shared node, after rewrites that keep what the template means:
 * - host directories ("/jupyter:/home/jovyan/work", a "${DATA:-/data/app}" bind) move into the
 *   resource directory, so every stack keeps its own data;
 * - binds of the host clock and entropy files are dropped: containers have their own;
 * - stacks with a web entry point keep their other ports private (reachable from the tenant's
 *   other resources) instead of publishing them on the node.
 * Templates that need more (the Docker socket, host system paths, extra capabilities, host
 * networking, public TCP/UDP ports...) are not offered.
 */
class ServiceTemplates
{
    /**
     * Bump when the rewrites or the checks change, so cached catalogs are rebuilt.
     */
    private const VERSION = 1;

    private const CACHE_KEY = 'platform:service-templates';

    /**
     * Host paths whose content comes from the node itself: a template that binds them needs the
     * host, and moving them would only hide that.
     */
    private const HOST_SYSTEM_PATHS = [
        '/', '/proc', '/sys', '/dev', '/run', '/var/run', '/var/lib/docker', '/var/lib/containerd', '/var/log', '/etc',
        '/boot', '/lib', '/lib32', '/lib64', '/libx32', '/usr', '/bin', '/sbin', '/root', '/home', '/mnt', '/media',
    ];

    /**
     * Host files every container already has an equivalent of.
     */
    private const HOST_FILES_CONTAINERS_HAVE = ['/etc/localtime', '/etc/timezone', '/dev/urandom', '/dev/random'];

    /**
     * @var array{hash: string, templates: Collection<string, mixed>}|null
     */
    private static ?array $memo = null;

    /**
     * Tenants get the platform catalog; the operator and background jobs get the templates as they are.
     *
     * @param  Collection<string, mixed>  $templates
     * @return Collection<string, mixed>
     */
    public static function forCurrentUser(Collection $templates): Collection
    {
        return self::actingForTenant() ? self::catalog($templates) : $templates;
    }

    /**
     * Templates offered to tenants, rewritten for the platform.
     *
     * @param  Collection<string, mixed>  $templates
     * @return Collection<string, mixed>
     */
    public static function catalog(Collection $templates): Collection
    {
        $hash = md5(self::VERSION.'|'.json_encode($templates));
        if (self::$memo && self::$memo['hash'] === $hash) {
            return self::$memo['templates'];
        }
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && ($cached['hash'] ?? null) === $hash) {
            $catalog = collect($cached['templates']);
        } else {
            $catalog = collect();
            foreach ($templates as $name => $template) {
                $result = self::evaluate((string) data_get($template, 'compose', ''));
                if (! is_null($result['reason'])) {
                    continue;
                }
                $template = is_object($template) ? clone $template : $template;
                data_set($template, 'compose', $result['compose']);
                $catalog->put($name, $template);
            }
            Cache::forever(self::CACHE_KEY, ['hash' => $hash, 'templates' => $catalog->all()]);
        }
        self::$memo = ['hash' => $hash, 'templates' => $catalog];

        return $catalog;
    }

    /**
     * Templates the platform does not offer, with the first reason for each.
     *
     * @param  Collection<string, mixed>  $templates
     * @return array<string, string>
     */
    public static function unavailable(Collection $templates): array
    {
        $unavailable = [];
        foreach ($templates as $name => $template) {
            $reason = self::evaluate((string) data_get($template, 'compose', ''))['reason'];
            if (! is_null($reason)) {
                $unavailable[(string) $name] = $reason;
            }
        }

        return $unavailable;
    }

    /**
     * @return array{compose: string, reason: ?string} the base64 compose for the platform, or why it is not offered
     */
    public static function evaluate(string $encodedCompose): array
    {
        $compose = self::decode($encodedCompose);
        if (is_null($compose)) {
            return ['compose' => $encodedCompose, 'reason' => 'The template has no valid compose file.'];
        }
        [$adapted, $changed] = self::adapt($compose);
        $violations = self::violations($adapted);
        if (count($violations) > 0) {
            return ['compose' => $encodedCompose, 'reason' => $violations[0]];
        }

        return ['compose' => $changed ? base64_encode(Yaml::dump($adapted, 10, 2)) : $encodedCompose, 'reason' => null];
    }

    /**
     * Refuse a compose file the platform can not run before anything is created from it.
     *
     * @throws PlatformPolicyViolation
     */
    public static function assertDeployable(string $rawCompose, ?string $resourceUuid = null): void
    {
        try {
            $compose = Yaml::parse($rawCompose);
        } catch (\Throwable) {
            // Coolify reports invalid YAML itself.
            return;
        }
        if (! is_array($compose)) {
            return;
        }
        $violations = self::violations($compose, $resourceUuid);
        if (count($violations) > 0) {
            throw new PlatformPolicyViolation($violations, 'Not available on the platform');
        }
    }

    /**
     * Rewrite a template for a shared node.
     *
     * @param  array<string, mixed>  $compose
     * @return array{0: array<string, mixed>, 1: bool} the compose file and whether it changed
     */
    public static function adapt(array $compose): array
    {
        $changed = false;
        $hasWebEntry = self::hasWebEntry($compose);
        foreach ((array) data_get($compose, 'services', []) as $serviceName => $service) {
            if (! is_array($service)) {
                continue;
            }
            if (array_key_exists('volumes', $service) && is_array($service['volumes'])) {
                $volumes = [];
                foreach ($service['volumes'] as $volume) {
                    $rewritten = self::rewriteVolume((string) $serviceName, $volume);
                    if ($rewritten !== $volume) {
                        $changed = true;
                    }
                    if (! is_null($rewritten)) {
                        $volumes[] = $rewritten;
                    }
                }
                if (count($volumes) > 0) {
                    $service['volumes'] = $volumes;
                } else {
                    unset($service['volumes']);
                }
            }
            if ($hasWebEntry) {
                if (array_key_exists('ports', $service)) {
                    unset($service['ports']);
                    $changed = true;
                }
                if (array_key_exists('labels', $service) && is_array($service['labels'])) {
                    $labels = self::withoutTcpRouters($service['labels']);
                    if ($labels !== $service['labels']) {
                        $service['labels'] = $labels;
                        $changed = true;
                    }
                }
            }
            $compose['services'][$serviceName] = $service;
        }

        return [$compose, $changed];
    }

    /**
     * @param  array<string, mixed>  $compose
     * @return array<int, string>
     */
    private static function violations(array $compose, ?string $resourceUuid = null): array
    {
        $violations = TenantContainerPolicy::violationsBeforeParsing($compose, HardeningContext::forUnparsedCompose($resourceUuid));
        foreach ((array) data_get($compose, 'services', []) as $serviceName => $service) {
            $image = data_get($service, 'image');
            if (filled($image) && ! preg_match(TenantInputGuard::PATTERN_SERVICE_IMAGE, (string) $image)) {
                $violations[] = "Service \"{$serviceName}\": invalid image name.";
            }
        }

        return $violations;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(string $encodedCompose): ?array
    {
        $raw = base64_decode($encodedCompose, true);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        try {
            $compose = Yaml::parse($raw);
        } catch (\Throwable) {
            return null;
        }

        return is_array($compose) ? $compose : null;
    }

    /**
     * @param  array<string, mixed>  $compose
     */
    private static function hasWebEntry(array $compose): bool
    {
        foreach ((array) data_get($compose, 'services', []) as $service) {
            foreach ((array) data_get($service, 'environment', []) as $key => $value) {
                $entry = is_int($key) ? (string) (is_scalar($value) ? $value : '') : $key.'='.(is_scalar($value) ? $value : '');
                if (preg_match('/SERVICE_(URL|FQDN)_[A-Za-z0-9_]+/', $entry)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return mixed the volume for the platform, or null when the bind is not needed there
     */
    private static function rewriteVolume(string $serviceName, mixed $volume): mixed
    {
        if (is_string($volume)) {
            $parsed = parseDockerVolumeString($volume);
            $source = (string) $parsed['source'];
            // Variables in the short syntax are named volumes for Coolify, which names them per resource.
            if (! (str_starts_with($source, '/') || str_starts_with($source, '.') || str_starts_with($source, '~'))) {
                return $volume;
            }
            $platformSource = self::platformSource($serviceName, $source, (string) $parsed['target']);
            if (is_null($platformSource)) {
                return null;
            }
            if ($platformSource === $source) {
                return $volume;
            }
            $mode = filled($parsed['mode'] ?? null) ? ':'.$parsed['mode'] : '';

            return $platformSource.':'.$parsed['target'].$mode;
        }
        if (is_array($volume) && data_get($volume, 'type') === 'bind') {
            $source = (string) data_get($volume, 'source', '');
            $platformSource = self::platformSource($serviceName, $source, (string) data_get($volume, 'target', ''));
            if (is_null($platformSource)) {
                return null;
            }
            $volume['source'] = $platformSource;
        }

        return $volume;
    }

    /**
     * Where a bind source goes on the platform: unchanged (inside the resource directory, or left
     * for the policy to refuse), moved into the resource directory, or null when it is dropped.
     */
    private static function platformSource(string $serviceName, string $source, string $target): ?string
    {
        $path = trim($source);
        if (str_starts_with($path, '$')) {
            // "${NAME:-default}": the default is what the template means. Without one, the
            // service gets a directory of its own.
            if (preg_match('/^\$\{[A-Za-z_][A-Za-z0-9_]*:?-([^}$]+)\}$/', $path, $matches)) {
                $path = trim($matches[1]);
            } else {
                $inner = trim(preg_replace('#[^A-Za-z0-9_./\-]#', '', $target) ?? '', '/');

                return './'.$serviceName.($inner !== '' ? '/'.$inner : '');
            }
        }
        if (! str_starts_with($path, '/')) {
            return $path;
        }
        if (str_contains($path, 'docker.sock') || str_contains($path, 'podman.sock')) {
            return $source;
        }
        $normalized = PathPolicy::normalize($path);
        if (is_null($normalized)) {
            return $source;
        }
        if (in_array($normalized, self::HOST_FILES_CONTAINERS_HAVE, true)) {
            return null;
        }
        if (PathPolicy::isWithin($normalized, [service_configuration_dir()])) {
            return $source;
        }
        foreach (self::HOST_SYSTEM_PATHS as $systemPath) {
            if ($normalized === $systemPath || ($systemPath !== '/' && str_starts_with($normalized, $systemPath.'/'))) {
                return $source;
            }
        }

        return '.'.$normalized;
    }

    /**
     * @param  array<int|string, mixed>  $labels
     * @return array<int|string, mixed>
     */
    private static function withoutTcpRouters(array $labels): array
    {
        $kept = [];
        foreach ($labels as $key => $value) {
            $label = is_int($key) ? (string) (is_scalar($value) ? $value : '') : (string) $key;
            if (preg_match('/^traefik\.(tcp|udp)\./i', trim($label))) {
                continue;
            }
            if (is_int($key)) {
                $kept[] = $value;
            } else {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    private static function actingForTenant(): bool
    {
        if (! isPlatform()) {
            return false;
        }
        $user = auth()->user();
        if (is_null($user)) {
            return false;
        }
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        $teamId = $token instanceof PersonalAccessToken ? data_get($token, 'team_id') : currentTeam()?->id;

        return ! is_null($teamId) && isPlatformTenant((int) $teamId);
    }

    /**
     * Forget the per-process catalog (tests, template updates).
     */
    public static function flush(): void
    {
        self::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }
}
