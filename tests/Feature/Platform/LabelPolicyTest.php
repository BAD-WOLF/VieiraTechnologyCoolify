<?php

use App\Services\Platform\LabelPolicy;

function labelPolicy(): LabelPolicy
{
    return new LabelPolicy(
        resourceUuid: 'abc123uuid',
        allowedHosts: ['app.example.com', 'www.app.example.com'],
        allowedNetworks: ['tenant-42', 'abc123uuid'],
        resourceIds: ['coolify.applicationid' => 7],
    );
}

it('accepts the Traefik and Caddy labels Coolify generates for the tenant domains', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'abc123uuid',
        domains: collect(['https://app.example.com', 'https://app.example.com/api']),
        is_force_https_enabled: true,
        onlyPort: '3000',
        is_gzip_enabled: true,
        is_stripprefix_enabled: true,
        redirect_direction: 'www',
        is_http_basic_auth_enabled: true,
        http_basic_auth_username: 'admin',
        http_basic_auth_password: 'secret',
    )->merge(fqdnLabelsForCaddy(
        network: 'tenant-42',
        uuid: 'abc123uuid',
        domains: collect(['https://app.example.com']),
        onlyPort: '3000',
        is_http_basic_auth_enabled: true,
        http_basic_auth_username: 'admin',
        http_basic_auth_password: 'secret',
    ))->merge(['coolify.applicationId=7', 'coolify.managed=true'])->values()->all();

    $result = labelPolicy()->check($labels);

    expect($result['violations'])->toBe([]);
});

it('rejects labels that hijack traffic or reach other tenants', function (string $label, string $expected) {
    $result = labelPolicy()->check([$label]);

    expect(implode(' | ', $result['violations']))->toContain($expected);
})->with([
    'tcp catch-all' => ['traefik.tcp.routers.x-abc123uuid.rule=HostSNI(`*`)', 'not allowed'],
    'udp router' => ['traefik.udp.routers.x-abc123uuid.entrypoints=dns', 'not allowed'],
    'foreign domain' => ['traefik.http.routers.https-0-abc123uuid.rule=Host(`victim.com`)', 'not one of your domains'],
    'host regexp' => ['traefik.http.routers.https-0-abc123uuid.rule=HostRegexp(`.+`)', 'matcher "HostRegexp"'],
    'or catch-all' => ['traefik.http.routers.https-0-abc123uuid.rule=Host(`app.example.com`) || PathPrefix(`/`)', '"||"'],
    'no host' => ['traefik.http.routers.https-0-abc123uuid.rule=PathPrefix(`/`)', 'exactly one Host()'],
    'priority' => ['traefik.http.routers.https-0-abc123uuid.priority=100000', 'option "priority"'],
    'foreign router name' => ['traefik.http.routers.https-0-victimuuid.rule=Host(`app.example.com`)', 'must contain the resource UUID'],
    'foreign service reference' => ['traefik.http.routers.https-0-abc123uuid.service=https-0-victimuuid', 'must contain the resource UUID'],
    'forwardauth' => ['traefik.http.middlewares.auth-abc123uuid.forwardauth.address=http://victim:8080', 'type "forwardauth"'],
    'errors' => ['traefik.http.middlewares.err-abc123uuid.errors.service=victim', 'type "errors"'],
    'plugin' => ['traefik.http.middlewares.p-abc123uuid.plugin.x.y=z', 'type "plugin"'],
    'server url' => ['traefik.http.services.s-abc123uuid.loadbalancer.server.url=http://10.0.0.5:8080', 'option "server.url"'],
    'servers transport' => ['traefik.http.services.s-abc123uuid.loadbalancer.serverstransport=insecure@file', 'option "serverstransport"'],
    'shared middleware redefinition' => ['traefik.http.middlewares.gzip.headers.customresponseheaders.X=1', 'can not be redefined'],
    'tls options store' => ['traefik.tls.stores.default.defaultcertificate.certfile=/x', 'not allowed'],
    'foreign tls domain' => ['traefik.http.routers.https-0-abc123uuid.tls.domains[0].main=victim.com', 'not one of your domains'],
    'admin entrypoint' => ['traefik.http.routers.https-0-abc123uuid.entrypoints=traefik', 'Entrypoint "traefik"'],
    'foreign docker network' => ['traefik.docker.network=coolify', 'must be one of your networks'],
    'caddy foreign site' => ['caddy_0=https://victim.com', 'not one of your domains'],
    'caddy wildcard site' => ['caddy_0=*.example.com', 'not one of your domains'],
    'caddy external upstream' => ['caddy_0.reverse_proxy=10.0.0.5:5432', 'reverse_proxy may only target'],
    'caddy file server' => ['caddy_0.file_server=browse', 'directive "file_server"'],
    'caddy foreign network' => ['caddy_ingress_network=coolify', 'must be one of your networks'],
    'foreign resource id' => ['coolify.applicationId=999', 'must reference this resource'],
]);

it('drops labels owned by the platform and docker compose', function () {
    $result = labelPolicy()->check(['tenant_id=1', 'coolify.tenantId=1', 'com.docker.compose.project=victim', 'my.label=ok']);

    expect($result['labels'])->toBe(['my.label=ok']);
});

it('accepts key-value maps and shared middlewares with the canonical definition', function () {
    $result = labelPolicy()->check([
        'traefik.enable' => true,
        'traefik.http.middlewares.gzip.compress' => 'true',
        'traefik.http.middlewares.redirect-to-https.redirectscheme.scheme' => 'https',
        'traefik.http.routers.http-0-abc123uuid.middlewares' => 'redirect-to-https,gzip,platform-ratelimit@file',
    ]);

    expect($result['violations'])->toBe([]);
});

it('rejects router and service options with unsafe values', function (string $label, string $expected) {
    expect(implode(' | ', labelPolicy()->check([$label])['violations']))->toContain($expected);
})->with([
    'tls that is not a boolean' => ['traefik.http.routers.https-0-abc123uuid.tls=maybe', 'Router tls must be true or false.'],
    'certificate resolver with spaces' => ['traefik.http.routers.https-0-abc123uuid.tls.certresolver=le resolver', 'Invalid value for router tls.certresolver.'],
    'tls options from another provider' => ['traefik.http.routers.https-0-abc123uuid.tls.options=default;x', 'Invalid value for router tls.options.'],
    'service port that is not a number' => ['traefik.http.services.https-0-abc123uuid.loadbalancer.server.port=80a', 'Service port must be numeric.'],
    'service scheme' => ['traefik.http.services.https-0-abc123uuid.loadbalancer.server.scheme=ftp', 'Service scheme must be http, https or h2c.'],
    'Host matcher without a domain' => ['traefik.http.routers.https-0-abc123uuid.rule=Host()', 'Router Host() matcher has no domain.'],
    'chain with a foreign middleware' => ['traefik.http.middlewares.chain-abc123uuid.chain.middlewares=auth-victimuuid', 'must contain the resource UUID'],
]);

it('accepts flag labels, safe router and service options and chains of own and shared middlewares', function () {
    $result = labelPolicy()->check([
        'traefik.enable',
        'traefik.http.routers.https-0-abc123uuid.tls=true',
        'traefik.http.routers.https-0-abc123uuid.tls.certresolver=letsencrypt',
        'traefik.http.services.https-0-abc123uuid.loadbalancer.server.scheme=h2c',
        'traefik.http.services.https-0-abc123uuid.loadbalancer.passhostheader=true',
        'traefik.http.middlewares.chain-abc123uuid.chain.middlewares=gzip,redirect-to-https,auth-abc123uuid,headers@file',
        'caddy_0=app.example.com, www.app.example.com,',
    ]);

    expect($result['violations'])->toBe([])
        ->and($result['labels'][0])->toBe('traefik.enable=');
});
