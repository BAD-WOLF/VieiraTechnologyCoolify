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
