<?php

use App\Exceptions\PlatformPolicyViolation;
use App\Services\Platform\ServiceTemplates;
use App\Services\Platform\TenantContainerPolicy;
use App\Services\Platform\TenantInputGuard;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    config([
        'platform.container.cap_add' => ['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'FSETID', 'KILL', 'SETGID', 'SETUID', 'NET_BIND_SERVICE'],
        'platform.container.runtime' => null,
    ]);
});

function templateOf(array $compose): string
{
    return base64_encode(Yaml::dump($compose, 10, 2));
}

it('moves host directories of a template into the resource directory', function () {
    [$compose, $changed] = ServiceTemplates::adapt([
        'services' => [
            'api' => [
                'image' => 'linuxserver/apprise-api:latest',
                'environment' => ['SERVICE_URL_APPRISE_8000'],
                'volumes' => [
                    '/apprise-api/config:/config',
                    './relative:/relative',
                    'named-data:/data',
                    '/tmp/cache:/cache:rw',
                    ['type' => 'bind', 'source' => '${FOUNDRY_DATA:-/data/foundryvtt}', 'target' => '/data'],
                    ['type' => 'bind', 'source' => '${NO_DEFAULT}', 'target' => '/srv/files'],
                ],
            ],
        ],
    ]);

    expect($changed)->toBeTrue()
        ->and($compose['services']['api']['volumes'])->toBe([
            './apprise-api/config:/config',
            './relative:/relative',
            'named-data:/data',
            './tmp/cache:/cache:rw',
            ['type' => 'bind', 'source' => './data/foundryvtt', 'target' => '/data'],
            ['type' => 'bind', 'source' => './api/srv/files', 'target' => '/srv/files'],
        ]);
});

it('drops binds of host files every container already has', function () {
    [$compose] = ServiceTemplates::adapt([
        'services' => [
            'metabase' => [
                'image' => 'metabase/metabase:latest',
                'volumes' => ['/dev/urandom:/dev/random:ro', '/etc/localtime:/etc/localtime:ro'],
            ],
        ],
    ]);

    expect($compose['services']['metabase'])->not->toHaveKey('volumes');
});

it('keeps host system paths for the policy to refuse', function () {
    [$compose, $changed] = ServiceTemplates::adapt([
        'services' => [
            'agent' => [
                'image' => 'portainer/agent:2.21',
                'volumes' => ['/var/run/docker.sock:/var/run/docker.sock', '/proc:/host/proc:ro', '/etc/ssl:/etc/ssl:ro', '/home/user:/home'],
            ],
        ],
    ]);

    expect($changed)->toBeFalse()
        ->and(ServiceTemplates::evaluate(templateOf($compose))['reason'])->toContain('Docker socket');
});

it('keeps the ports of a web stack private and refuses stacks that only publish ports', function () {
    $web = [
        'services' => [
            'gitea' => [
                'image' => 'gitea/gitea:latest',
                'environment' => ['SERVICE_URL_GITEA_3000'],
                'ports' => ['22222:22'],
                'labels' => ['traefik.tcp.routers.ssh.entrypoints=ssh', 'traefik.enable=true'],
            ],
        ],
    ];
    [$compose, $changed] = ServiceTemplates::adapt($web);
    expect($changed)->toBeTrue()
        ->and($compose['services']['gitea'])->not->toHaveKey('ports')
        ->and($compose['services']['gitea']['labels'])->toBe(['traefik.enable=true'])
        ->and(ServiceTemplates::evaluate(templateOf($web))['reason'])->toBeNull();

    $gameServer = ['services' => ['mc' => ['image' => 'itzg/minecraft-server', 'ports' => ['25565:25565']]]];
    expect(ServiceTemplates::evaluate(templateOf($gameServer))['reason'])->toContain('publishing host ports');
});

it('offers unchanged templates byte for byte', function () {
    $encoded = templateOf(['services' => ['kuma' => ['image' => 'louislam/uptime-kuma:1', 'environment' => ['SERVICE_URL_KUMA_3001'], 'volumes' => ['kuma-data:/app/data']]]]);

    expect(ServiceTemplates::evaluate($encoded))->toBe(['compose' => $encoded, 'reason' => null]);
});

it('checks a compose file as written, leaving what the parser generates to the deployment check', function () {
    $written = [
        'services' => [
            'app' => [
                'image' => 'ghcr.io/org/app:${TAG:-1.2}',
                'exclude_from_hc' => true,
                'volumes' => ['app-data:/data', './config:/config'],
            ],
        ],
    ];
    expect(TenantContainerPolicy::violationsBeforeParsing($written))->toBe([]);

    $escaping = ['services' => ['app' => ['image' => 'alpine', 'privileged' => true, 'network_mode' => 'host', 'cap_add' => ['SYS_ADMIN']]]];
    expect(TenantContainerPolicy::violationsBeforeParsing($escaping))->toHaveCount(3);
});

it('refuses a compose stack with the reasons', function () {
    expect(fn () => ServiceTemplates::assertDeployable("services:\n  app:\n    image: alpine\n    volumes:\n      - /:/host\n"))
        ->toThrow(PlatformPolicyViolation::class, 'outside of this resource');
    ServiceTemplates::assertDeployable("services:\n  app:\n    image: alpine\n");
    // Invalid YAML is reported by Coolify itself.
    ServiceTemplates::assertDeployable("services: [\n");
});

it('accepts compose variables in service images but nothing a shell would run', function (string $image, bool $accepted) {
    expect((bool) preg_match(TenantInputGuard::PATTERN_SERVICE_IMAGE, $image))->toBe($accepted);
})->with([
    ['ghcr.io/toeverything/affine:${AFFINE_REVISION:-stable}', true],
    ['svhd/logto:${TAG-latest}', true],
    ['${IMAGE:-nginx}:1.27', true],
    ['redis@sha256:abc123', true],
    ['img:$(id)', false],
    ['img:${X:-$(id)}', false],
    ['img;reboot', false],
    ['-entrypoint', false],
    ['img:${X:-a b}', false],
]);
