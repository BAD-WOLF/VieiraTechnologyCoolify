<?php

namespace App\Services\Platform;

/**
 * Validates container labels of tenant workloads.
 *
 * The regional proxy (Traefik/Caddy) reads labels from every container on the node, so a
 * tenant label could otherwise hijack another tenant's domain (Host/HostSNI rules, TCP routers),
 * reach internal services through the proxy (forwardauth, server.url, errors) or break shared
 * middlewares. Only the shapes Coolify itself generates are accepted, scoped to the tenant's
 * own domains, networks and resource UUID.
 */
class LabelPolicy
{
    /**
     * Middlewares every Coolify container defines with the exact same configuration.
     *
     * @var array<string, array<string, string>>
     */
    private const SHARED_MIDDLEWARES = [
        'gzip' => ['compress' => 'true'],
        'redirect-to-https' => ['redirectscheme.scheme' => 'https'],
    ];

    private const ALLOWED_MIDDLEWARE_TYPES = [
        'compress', 'redirectscheme', 'redirectregex', 'stripprefix', 'stripprefixregex', 'addprefix',
        'basicauth', 'digestauth', 'headers', 'ratelimit', 'inflightreq', 'retry', 'buffering', 'chain',
        'ipallowlist', 'ipwhitelist', 'replacepath', 'replacepathregex', 'circuitbreaker', 'contenttype', 'grpcweb',
    ];

    private const ALLOWED_RULE_MATCHERS = [
        'host', 'pathprefix', 'path', 'pathregexp', 'method', 'header', 'headerregexp', 'query', 'queryregexp', 'clientip',
    ];

    private const ALLOWED_ENTRYPOINTS = ['http', 'https'];

    /**
     * Label keys that are always replaced by the platform.
     */
    private const PLATFORM_OWNED_KEYS = ['tenant_id', 'tenant_plan', 'coolify.tenantid'];

    /**
     * @param  array<int, string>  $allowedHosts  hosts owned by the tenant (lowercase)
     * @param  array<int, string>  $allowedNetworks  docker networks the tenant may use
     * @param  array<string, int|string>  $resourceIds  expected values for coolify.applicationId / serviceId / databaseId
     */
    public function __construct(
        private string $resourceUuid,
        private array $allowedHosts,
        private array $allowedNetworks,
        private array $resourceIds = [],
    ) {
        $this->resourceUuid = strtolower($resourceUuid);
        $this->allowedHosts = array_values(array_unique(array_map(fn ($host) => strtolower(trim((string) $host)), $allowedHosts)));
    }

    /**
     * @param  array<int|string, mixed>  $labels  list of "key=value" strings or a key => value map
     * @return array{labels: array<int, string>, violations: array<int, string>}
     */
    public function check(array $labels): array
    {
        $violations = [];
        $normalized = [];
        foreach (self::toPairs($labels) as [$key, $value]) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, self::PLATFORM_OWNED_KEYS, true) || str_starts_with($lowerKey, 'com.docker.compose.')) {
                continue;
            }
            $literal = self::literal($value);
            if (str_starts_with($lowerKey, 'traefik.')) {
                $this->checkTraefik($lowerKey, $literal, $violations);
            } elseif ($lowerKey === 'caddy_ingress_network' || preg_match('/^caddy(_\d+)?(\.|$)/', $lowerKey)) {
                $this->checkCaddy($lowerKey, $literal, $violations);
            } elseif (in_array($lowerKey, ['coolify.applicationid', 'coolify.serviceid', 'coolify.databaseid'], true)) {
                $expected = $this->resourceIds[$lowerKey] ?? null;
                if (! is_null($expected) && (string) $expected !== $literal) {
                    $violations[] = "Label {$key} must reference this resource.";
                }
            }
            $normalized[] = $key.'='.self::escape($literal);
        }

        return ['labels' => $normalized, 'violations' => array_values(array_unique($violations))];
    }

    /**
     * @param  array<int|string, mixed>  $labels
     * @return array<int, array{0: string, 1: string}>
     */
    public static function toPairs(array $labels): array
    {
        $pairs = [];
        foreach ($labels as $key => $value) {
            if (is_int($key)) {
                $value = (string) $value;
                if (! str_contains($value, '=')) {
                    $pairs[] = [trim($value), ''];

                    continue;
                }
                $pairs[] = [trim(str($value)->before('=')->value()), str($value)->after('=')->value()];
            } else {
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                $pairs[] = [trim((string) $key), (string) $value];
            }
        }

        return $pairs;
    }

    /**
     * Value as docker compose will deliver it, treating every "$" literally ("$$" is an escaped "$").
     */
    public static function literal(string $value): string
    {
        return str_replace("\0", '$', str_replace('$$', "\0", $value));
    }

    /**
     * Escape a literal value so that compose does not interpolate it with tenant-controlled variables.
     */
    public static function escape(string $literal): string
    {
        return str_replace('$', '$$', $literal);
    }

    /**
     * @param  array<int, string>  $violations
     */
    private function checkTraefik(string $key, string $value, array &$violations): void
    {
        if ($key === 'traefik.enable') {
            return;
        }
        if ($key === 'traefik.docker.network') {
            if (! in_array($value, $this->allowedNetworks, true)) {
                $violations[] = "traefik.docker.network must be one of your networks ({$value} is not).";
            }

            return;
        }
        if (preg_match('/^traefik\.http\.routers\.([^.]+)\.(.+)$/', $key, $matches)) {
            [$name, $property] = [$matches[1], $matches[2]];
            $this->assertOwnName($name, 'router', $violations);
            $this->checkRouterProperty($property, $value, $violations);

            return;
        }
        if (preg_match('/^traefik\.http\.services\.([^.]+)\.loadbalancer\.(.+)$/', $key, $matches)) {
            [$name, $property] = [$matches[1], $matches[2]];
            $this->assertOwnName($name, 'service', $violations);
            $this->checkServiceProperty($property, $value, $violations);

            return;
        }
        if (preg_match('/^traefik\.http\.middlewares\.([^.]+)\.([^.]+)(?:\.(.+))?$/', $key, $matches)) {
            $name = $matches[1];
            $type = $matches[2];
            $property = $matches[3] ?? '';
            if (array_key_exists($name, self::SHARED_MIDDLEWARES)) {
                $expected = self::SHARED_MIDDLEWARES[$name];
                $path = $property === '' ? $type : "{$type}.{$property}";
                if (! array_key_exists($path, $expected) || strtolower($expected[$path]) !== strtolower($value)) {
                    $violations[] = "The shared middleware \"{$name}\" can not be redefined.";
                }

                return;
            }
            $this->assertOwnName($name, 'middleware', $violations);
            if (! in_array($type, self::ALLOWED_MIDDLEWARE_TYPES, true)) {
                $violations[] = "Traefik middleware type \"{$type}\" is not allowed.";

                return;
            }
            if ($type === 'chain') {
                $this->checkMiddlewareReferences($value, $violations);
            }

            return;
        }
        $violations[] = "Traefik label \"{$key}\" is not allowed (only HTTP routers, services and middlewares scoped to this resource are accepted).";
    }

    /**
     * @param  array<int, string>  $violations
     */
    private function checkRouterProperty(string $property, string $value, array &$violations): void
    {
        if ($property === 'rule') {
            $this->checkRule($value, $violations);

            return;
        }
        if ($property === 'entrypoints') {
            foreach (array_filter(array_map('trim', explode(',', strtolower($value)))) as $entrypoint) {
                if (! in_array($entrypoint, self::ALLOWED_ENTRYPOINTS, true)) {
                    $violations[] = "Entrypoint \"{$entrypoint}\" is not allowed.";
                }
            }

            return;
        }
        if ($property === 'service') {
            $this->assertOwnName(str($value)->before('@')->value(), 'service', $violations);

            return;
        }
        if ($property === 'middlewares') {
            $this->checkMiddlewareReferences($value, $violations);

            return;
        }
        if ($property === 'tls') {
            if (! in_array(strtolower($value), ['true', 'false'], true)) {
                $violations[] = 'Router tls must be true or false.';
            }

            return;
        }
        if (in_array($property, ['tls.certresolver', 'tls.options'], true)) {
            if (! preg_match('/^[A-Za-z0-9_\-@.]+$/', $value)) {
                $violations[] = "Invalid value for router {$property}.";
            }

            return;
        }
        if (preg_match('/^tls\.domains\[\d+\]\.(main|sans)$/', $property)) {
            foreach (array_filter(array_map('trim', explode(',', $value))) as $host) {
                if (! $this->hostAllowed($host)) {
                    $violations[] = "TLS domain \"{$host}\" is not one of your domains.";
                }
            }

            return;
        }
        $violations[] = "Router option \"{$property}\" is not allowed.";
    }

    /**
     * @param  array<int, string>  $violations
     */
    private function checkServiceProperty(string $property, string $value, array &$violations): void
    {
        if ($property === 'server.port') {
            if (! preg_match('/^\d{1,5}$/', $value)) {
                $violations[] = 'Service port must be numeric.';
            }

            return;
        }
        if ($property === 'server.scheme') {
            if (! in_array(strtolower($value), ['http', 'https', 'h2c'], true)) {
                $violations[] = 'Service scheme must be http, https or h2c.';
            }

            return;
        }
        if (in_array($property, ['passhostheader', 'responseforwarding.flushinterval', 'sticky.cookie', 'sticky.cookie.name', 'sticky.cookie.secure', 'sticky.cookie.httponly', 'sticky.cookie.samesite', 'sticky.cookie.maxage', 'healthcheck.path', 'healthcheck.interval', 'healthcheck.timeout', 'healthcheck.port', 'healthcheck.scheme'], true)) {
            return;
        }
        $violations[] = "Service option \"{$property}\" is not allowed.";
    }

    /**
     * @param  array<int, string>  $violations
     */
    private function checkMiddlewareReferences(string $value, array &$violations): void
    {
        foreach (array_filter(array_map('trim', explode(',', $value))) as $reference) {
            if (str_ends_with(strtolower($reference), '@file')) {
                continue;
            }
            $name = str($reference)->before('@')->value();
            if (array_key_exists($name, self::SHARED_MIDDLEWARES)) {
                continue;
            }
            $this->assertOwnName($name, 'middleware', $violations);
        }
    }

    /**
     * Only conjunctions of safe matchers with a Host() limited to the tenant's domains.
     *
     * @param  array<int, string>  $violations
     */
    private function checkRule(string $rule, array &$violations): void
    {
        if (str_contains($rule, '||') || preg_match('/!\s*[A-Za-z(]/', $rule)) {
            $violations[] = 'Router rules can not use "||" or "!".';

            return;
        }
        preg_match_all('/([A-Za-z]+)\s*\(/', $rule, $matchers);
        foreach ($matchers[1] as $matcher) {
            if (! in_array(strtolower($matcher), self::ALLOWED_RULE_MATCHERS, true)) {
                $violations[] = "Router matcher \"{$matcher}\" is not allowed.";
            }
        }
        preg_match_all('/\bHost\s*\(([^)]*)\)/i', $rule, $hostMatchers);
        if (count($hostMatchers[1]) !== 1) {
            $violations[] = 'Router rules must contain exactly one Host() matcher.';

            return;
        }
        preg_match_all('/`([^`]*)`|"([^"]*)"/', $hostMatchers[1][0], $arguments, PREG_SET_ORDER);
        if (count($arguments) === 0) {
            $violations[] = 'Router Host() matcher has no domain.';
        }
        foreach ($arguments as $argument) {
            $host = $argument[1] !== '' ? $argument[1] : ($argument[2] ?? '');
            if (! $this->hostAllowed($host)) {
                $violations[] = "Domain \"{$host}\" is not one of your domains.";
            }
        }
    }

    /**
     * @param  array<int, string>  $violations
     */
    private function checkCaddy(string $key, string $value, array &$violations): void
    {
        if ($key === 'caddy_ingress_network') {
            if (! in_array($value, $this->allowedNetworks, true)) {
                $violations[] = "caddy_ingress_network must be one of your networks ({$value} is not).";
            }

            return;
        }
        if (preg_match('/^caddy(_\d+)?$/', $key)) {
            foreach (preg_split('/[\s,]+/', trim($value)) ?: [] as $address) {
                if ($address === '') {
                    continue;
                }
                $host = parse_url(str_contains($address, '://') ? $address : "http://{$address}", PHP_URL_HOST);
                if (! is_string($host) || ! $this->hostAllowed($host)) {
                    $violations[] = "Caddy site \"{$address}\" is not one of your domains.";
                }
            }

            return;
        }
        $directive = (string) preg_replace('/^caddy(_\d+)?\./', '', $key);
        $upstream = '/^\{\{upstreams( (http|https|h2c))?( \d{1,5})?\}\}$/';
        if (preg_match('/^((handle_path|handle)\.\d+_)?reverse_proxy$/', $directive)) {
            if (! preg_match($upstream, trim($value))) {
                $violations[] = 'Caddy reverse_proxy may only target the container itself ({{upstreams PORT}}).';
            }

            return;
        }
        $allowed = [
            '/^header$/', '/^try_files$/', '/^encode$/', '/^redir$/', '/^handle_path$/', '/^handle$/',
            '/^(basicauth|basic_auth)(\.[A-Za-z0-9_\-.@]+)?$/',
            '/^(handle_path|handle)\.\d+_redir-ghost-[a-z0-9\-]+\.(handler|rewrite\.regexp|rewrite\.replacement)$/',
        ];
        foreach ($allowed as $pattern) {
            if (preg_match($pattern, $directive)) {
                return;
            }
        }
        $violations[] = "Caddy directive \"{$directive}\" is not allowed.";
    }

    /**
     * @param  array<int, string>  $violations
     */
    private function assertOwnName(string $name, string $kind, array &$violations): void
    {
        if ($this->resourceUuid === '' || ! str_contains(strtolower($name), $this->resourceUuid)) {
            $violations[] = "Traefik {$kind} names must contain the resource UUID ({$this->resourceUuid}); \"{$name}\" does not.";
        }
    }

    private function hostAllowed(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || str_contains($host, '*') || str_contains($host, '{')) {
            return false;
        }

        return in_array($host, $this->allowedHosts, true);
    }
}
