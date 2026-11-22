<?php

use App\Exceptions\PlatformPolicyViolation;
use App\Services\Platform\HardeningContext;
use App\Services\Platform\TenantContainerPolicy;

function platformContext(array $overrides = []): HardeningContext
{
    $values = array_merge([
        'teamId' => 42,
        'plan' => 'pro',
        'resourceUuid' => 'abc123uuid',
        'resourceKind' => 'service',
        'baseDirectory' => '/data/coolify/services/abc123uuid',
        'bindRoots' => ['/data/coolify/services/abc123uuid'],
        'allowedNetworks' => ['tenant-42', 'abc123uuid'],
        'allowedHosts' => ['app.example.com', 'abc123uuid.apps.example.com'],
        'maxCpus' => 2.0,
        'maxMemoryMb' => 4096,
        'defaultCpus' => 0.5,
        'defaultMemoryMb' => 512,
        'pidsLimit' => 512,
        'resourceIds' => ['coolify.serviceid' => 7],
    ], $overrides);

    return new HardeningContext(...$values);
}

function hardenCompose(array $compose, array $context = []): array
{
    return (new TenantContainerPolicy(platformContext($context)))->apply($compose);
}

function violationsFor(array $compose, array $context = []): array
{
    try {
        hardenCompose($compose, $context);
    } catch (PlatformPolicyViolation $e) {
        return $e->violations;
    }

    return [];
}

beforeEach(function () {
    config([
        'platform.container.cap_add' => ['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'FSETID', 'KILL', 'SETGID', 'SETUID', 'NET_BIND_SERVICE'],
        'platform.container.oom_score_adj' => 500,
        'platform.container.runtime' => null,
        'platform.container.enforce_storage_opt' => false,
    ]);
});

it('hardens a regular service with limits, dropped capabilities and tenant labels', function () {
    $result = hardenCompose([
        'services' => [
            'web' => [
                'image' => 'nginx:alpine',
                'container_name' => 'web-abc123uuid',
                'volumes' => ['./data:/usr/share/nginx/html', 'abc123uuid_cache:/cache'],
                'networks' => ['abc123uuid'],
                'labels' => ['coolify.managed=true'],
            ],
        ],
        'volumes' => ['abc123uuid_cache' => ['name' => 'abc123uuid_cache']],
        'networks' => ['abc123uuid' => ['name' => 'abc123uuid', 'external' => true]],
    ]);

    $web = $result['compose']['services']['web'];
    expect($web['cap_drop'])->toBe(['ALL'])
        ->and($web['cap_add'])->toContain('CHOWN')->not->toContain('NET_RAW')
        ->and($web['security_opt'])->toBe(['no-new-privileges:true'])
        ->and($web['mem_limit'])->toBe('512m')
        ->and($web['memswap_limit'])->toBe('512m')
        ->and($web['cpus'])->toBe(0.5)
        ->and($web['pids_limit'])->toBe(512)
        ->and($web['oom_score_adj'])->toBe(500)
        ->and($web['logging']['driver'])->toBe('json-file')
        ->and($web['labels'])->toContain('tenant_id=42')
        ->and($web['labels'])->toContain('coolify.tenantId=42');
    expect($result['cpus'])->toBe(0.5)
        ->and($result['memory_mb'])->toBe(512)
        ->and($result['containers'])->toBe(1);
});

it('clamps requested limits to the plan and counts replicas', function () {
    $result = hardenCompose([
        'services' => [
            'worker' => [
                'image' => 'busybox',
                'mem_limit' => '16g',
                'cpus' => 8,
                'pids_limit' => 100000,
                'deploy' => ['replicas' => 2, 'resources' => ['limits' => ['memory' => '16g']]],
            ],
        ],
    ]);

    $worker = $result['compose']['services']['worker'];
    expect($worker['mem_limit'])->toBe('4096m')
        ->and($worker['cpus'])->toBe(2.0)
        ->and($worker['pids_limit'])->toBe(512)
        ->and(data_get($worker, 'deploy.resources'))->toBeNull();
    expect($result['containers'])->toBe(2)
        ->and($result['memory_mb'])->toBe(8192);
});

it('rejects workloads that could escape the sandbox', function (array $service, string $expected) {
    $violations = violationsFor(['services' => ['app' => array_merge(['image' => 'alpine'], $service)]]);

    expect(implode(' | ', $violations))->toContain($expected);
})->with([
    'privileged' => [['privileged' => true], 'privileged mode'],
    'host network' => [['network_mode' => 'host'], 'network_mode "host"'],
    'other container network' => [['network_mode' => 'container:victim'], 'network_mode'],
    'host pid' => [['pid' => 'host'], 'pid "host"'],
    'host ipc' => [['ipc' => 'host'], 'ipc "host"'],
    'sys admin' => [['cap_add' => ['SYS_ADMIN']], 'capability SYS_ADMIN'],
    'net raw' => [['cap_add' => ['NET_RAW']], 'capability NET_RAW'],
    'devices' => [['devices' => ['/dev/kvm:/dev/kvm']], 'host devices'],
    'docker socket' => [['volumes' => ['/var/run/docker.sock:/var/run/docker.sock']], 'Docker socket'],
    'host root' => [['volumes' => ['/:/host']], 'outside of this resource'],
    'host etc' => [['volumes' => ['/etc:/etc']], 'outside of this resource'],
    'path traversal' => [['volumes' => ['./../other-tenant:/data']], 'outside of this resource'],
    'absolute traversal' => [['volumes' => ['/data/coolify/services/abc123uuid/../victim:/data']], 'outside of this resource'],
    'variable bind' => [['volumes' => ['${HOST_DIR}:/data']], 'volume'],
    'long syntax bind' => [['volumes' => [['type' => 'bind', 'source' => '/root', 'target' => '/r']]], 'outside of this resource'],
    'seccomp off' => [['security_opt' => ['seccomp=unconfined']], 'security option'],
    'apparmor off' => [['security_opt' => ['apparmor:unconfined']], 'security option'],
    'kernel sysctl' => [['sysctls' => ['kernel.panic' => 1]], 'sysctl kernel.panic'],
    'api socket' => [['use_api_socket' => true], 'Docker API socket'],
    'provider service' => [['provider' => ['type' => 'model']], 'provider services'],
    'unknown option' => [['some_future_option' => true], 'not supported'],
    'gpus' => [['gpus' => 'all'], 'GPUs'],
    'userns host' => [['userns_mode' => 'host'], 'user namespace'],
    'cgroup parent' => [['cgroup_parent' => 'system.slice'], 'cgroup parent'],
    'foreign volumes_from' => [['volumes_from' => ['container:victim']], 'volumes_from'],
    'custom runtime' => [['runtime' => 'runc'], 'runtime "runc"'],
    'build from host root' => [['build' => ['context' => '/']], 'build context'],
    'build host network' => [['build' => ['context' => '.', 'network' => 'host']], 'build network'],
    'build privileged' => [['build' => ['context' => '.', 'privileged' => true]], 'build option "privileged"'],
    'build ssh' => [['build' => ['context' => '.', 'ssh' => ['default']]], 'build option "ssh"'],
    'env file from host' => [['env_file' => ['/root/.env']], 'env_file'],
    'env file traversal' => [['env_file' => ['../../.env']], 'env_file'],
    'foreign container name' => [['container_name' => 'coolify-proxy'], 'container_name'],
    'gpu reservation' => [['deploy' => ['resources' => ['reservations' => ['devices' => [['capabilities' => ['gpu']]]]]]], 'device reservations'],
    'privileged hook' => [['post_start' => [['command' => 'id', 'privileged' => true]]], 'privileged lifecycle hooks'],
]);

it('rejects foreign networks, volume drivers and top-level escapes', function (array $compose, string $expected) {
    $violations = violationsFor(array_merge(['services' => ['app' => ['image' => 'alpine']]], $compose));

    expect(implode(' | ', $violations))->toContain($expected);
})->with([
    'coolify network' => [['networks' => ['coolify' => ['external' => true]]], 'does not belong to your team'],
    'other tenant network' => [['networks' => ['net' => ['external' => true, 'name' => 'tenant-7']]], 'does not belong to your team'],
    'macvlan' => [['networks' => ['lan' => ['driver' => 'macvlan', 'driver_opts' => ['parent' => 'eth0']]]], 'driver "macvlan"'],
    'ipam subnet' => [['networks' => ['own' => ['ipam' => ['config' => [['subnet' => '10.0.0.0/16']]]]]], 'ipam is not allowed'],
    'bind volume driver' => [['volumes' => ['data' => ['driver_opts' => ['type' => 'none', 'o' => 'bind', 'device' => '/']]]], 'driver_opts are not allowed'],
    'nfs volume driver' => [['volumes' => ['data' => ['driver' => 'nfs']]], 'driver "nfs"'],
    'foreign external volume' => [['volumes' => ['data' => ['external' => true, 'name' => 'postgres-data-victim']]], 'must belong to this resource'],
    'include' => [['include' => ['/etc/compose.yml']], 'Top-level compose key "include"'],
    'host secret' => [['secrets' => ['key' => ['file' => '/root/.ssh/id_rsa']]], 'outside of this resource'],
    'host config' => [['configs' => ['cfg' => ['file' => '/etc/shadow']]], 'outside of this resource'],
]);

it('accepts bind mounts inside the resource directory and own networks', function () {
    $result = hardenCompose([
        'services' => [
            'db' => [
                'image' => 'postgres:16',
                'volumes' => [
                    '/data/coolify/services/abc123uuid/init:/docker-entrypoint-initdb.d',
                    ['type' => 'bind', 'source' => './conf', 'target' => '/etc/conf', 'read_only' => true],
                    ['type' => 'tmpfs', 'target' => '/tmp'],
                ],
                'networks' => ['tenant-42' => null, 'internal' => null],
                'network_mode' => null,
            ],
            'sidecar' => ['image' => 'alpine', 'network_mode' => 'service:db'],
        ],
        'networks' => [
            'tenant-42' => ['external' => true],
            'internal' => ['internal' => true],
        ],
    ]);

    expect($result['containers'])->toBe(2);
});

it('escapes compose interpolation in labels so tenant variables can not change proxy rules', function () {
    $result = hardenCompose([
        'services' => [
            'web' => [
                'image' => 'nginx',
                'labels' => [
                    'traefik.http.middlewares.0-abc123uuid-to-www.redirectregex.replacement=${1}://www.${2}',
                ],
            ],
        ],
    ]);

    expect($result['compose']['services']['web']['labels'])
        ->toContain('traefik.http.middlewares.0-abc123uuid-to-www.redirectregex.replacement=$${1}://www.$${2}');
});

it('exposes the container ports instead of publishing host ports', function () {
    $result = hardenCompose(['services' => [
        'web' => ['image' => 'nginx', 'ports' => ['8000:80', '127.0.0.1:8443:443/tcp', '5353:53/udp', '9000-9002:9000-9002'], 'expose' => ['3000']],
        'api' => ['image' => 'api', 'ports' => [['target' => 8080, 'published' => 18080, 'protocol' => 'tcp'], '${PORT}:${PORT}']],
        'plain' => ['image' => 'plain', 'ports' => [80]],
    ]]);

    expect($result['compose']['services']['web'])->not->toHaveKey('ports')
        ->and($result['compose']['services']['web']['expose'])->toBe(['3000', '80', '443', '53/udp', '9000-9002'])
        ->and($result['compose']['services']['api']['expose'])->toBe(['8080'])
        ->and($result['compose']['services']['plain']['expose'])->toBe(['80'])
        ->and($result['notices'])->toHaveCount(3)
        ->and($result['notices'][0])->toContain('host ports (8000:80, 127.0.0.1:8443:443/tcp, 5353:53/udp, 9000-9002:9000-9002) are not published');
});

it('pins the proxy to the network of the resource for routed services with more networks', function () {
    $compose = [
        'services' => [
            'web' => [
                'image' => 'nginx:alpine',
                'networks' => ['site', 'abc123uuid'],
                'labels' => ['traefik.enable=true', 'traefik.http.routers.http-0-abc123uuid-web.rule=Host(`app.example.com`)'],
            ],
            'worker' => ['image' => 'busybox', 'networks' => ['site', 'abc123uuid']],
            'pinned' => [
                'image' => 'nginx:alpine',
                'networks' => ['site' => null, 'tenant-42' => null],
                'labels' => ['traefik.enable' => 'true', 'traefik.docker.network' => 'tenant-42'],
            ],
        ],
        'networks' => [
            'site' => null,
            'abc123uuid' => ['name' => 'abc123uuid', 'external' => true],
            'tenant-42' => ['name' => 'tenant-42', 'external' => true],
        ],
    ];
    $networkLabels = fn (array $services, string $service) => array_values(array_filter($services[$service]['labels'], fn ($label) => str_starts_with($label, 'traefik.docker.network=')));
    $services = hardenCompose($compose)['compose']['services'];

    expect($networkLabels($services, 'web'))->toBe(['traefik.docker.network=abc123uuid'])
        ->and($networkLabels($services, 'worker'))->toBe([])
        ->and($networkLabels($services, 'pinned'))->toBe(['traefik.docker.network=tenant-42']);

    // A preview joins the network of its pull request.
    $compose['services']['web']['networks'] = ['site', 'abc123uuid-7'];
    $compose['networks']['abc123uuid-7'] = ['name' => 'abc123uuid-7', 'external' => true];
    $services = hardenCompose($compose, ['allowedNetworks' => ['tenant-42', 'abc123uuid', 'abc123uuid-7']])['compose']['services'];
    expect($networkLabels($services, 'web'))->toBe(['traefik.docker.network=abc123uuid-7']);
});

it('refuses a plan without CPU or memory', function () {
    $violations = violationsFor(['services' => ['app' => ['image' => 'alpine']]], ['maxCpus' => 0.0, 'maxMemoryMb' => 0, 'defaultCpus' => 0.0, 'defaultMemoryMb' => 0]);

    expect(implode(' ', $violations))->toContain('does not include CPU/memory');
});

it('parses memory units', function () {
    $policy = new TenantContainerPolicy(platformContext());

    expect($policy->memoryToMb('512m'))->toBe(512)
        ->and($policy->memoryToMb('1g'))->toBe(1024)
        ->and($policy->memoryToMb('1.5GB'))->toBe(1536)
        ->and($policy->memoryToMb(1073741824))->toBe(1024)
        ->and($policy->memoryToMb('0'))->toBe(0)
        ->and($policy->memoryToMb('garbage'))->toBe(0);
});

it('rejects more ways for a service to reach the host or other resources', function (array $service, string $expected) {
    $violations = violationsFor(['services' => ['app' => array_merge(['image' => 'alpine'], $service)]]);

    expect(implode(' | ', $violations))->toContain($expected);
})->with([
    'network from a variable' => [['networks' => ['${NETWORK}']], 'network names can not use variables'],
    'volume that is not a definition' => [['volumes' => [42]], 'invalid volume definition'],
    'undeclared foreign named volume' => [['volumes' => ['shared-data:/data']], 'named volume "shared-data" must be declared or belong to this resource'],
    'unknown volume type' => [['volumes' => [['type' => 'npipe', 'source' => 'pipe', 'target' => '/pipe']]], 'volume type "npipe" is not allowed'],
    'host path from a variable' => [['volumes' => [['type' => 'bind', 'source' => '${HOME}', 'target' => '/home']]], 'Host paths can not use variables'],
    'build that is not a definition' => [['build' => 42], 'invalid build definition'],
    'build context from a variable' => [['build' => ['context' => '${CONTEXT}']], 'build context can not use variables'],
    'Dockerfile outside of the context' => [['build' => ['context' => '.', 'dockerfile' => '../../Dockerfile']], 'the Dockerfile must be a path inside the build context'],
    'absolute Dockerfile' => [['build' => ['context' => '.', 'dockerfile' => '/etc/Dockerfile']], 'the Dockerfile must be a path inside the build context'],
    'local build cache' => [['build' => ['context' => '.', 'cache_from' => ['type=local,src=/var/lib/docker']]], 'local build caches are not allowed'],
    'additional build context on the host' => [['build' => ['context' => '.', 'additional_contexts' => ['root' => '/']]], 'additional context root "/" must stay inside the project'],
    'env file from a variable' => [['env_file' => [['path' => '${ENV_FILE}']]], 'env_file can not use variables'],
    'proxy label for a foreign domain' => [['labels' => ['traefik.http.routers.https-0-abc123uuid.rule=Host(`victim.com`)']], 'Service "app": Domain "victim.com" is not one of your domains.'],
]);

it('rejects more top-level definitions that leave the resource', function (array $compose, string $expected) {
    $violations = violationsFor(array_merge(['services' => ['app' => ['image' => 'alpine']]], $compose));

    expect(implode(' | ', $violations))->toContain($expected);
})->with([
    'external config' => [['configs' => ['cfg' => ['external' => true]]], 'External or driver based configs ("cfg") are not allowed.'],
    'secret from a driver' => [['secrets' => ['key' => ['driver' => 'vault']]], 'External or driver based secrets ("key") are not allowed.'],
    'service that is not a definition' => [['services' => ['app' => 'alpine']], 'Service "app" is not a valid definition.'],
    'external network named inside external' => [['networks' => ['net' => ['external' => ['name' => 'tenant-7']]]], 'Network "tenant-7" does not belong to your team.'],
    'external volume named inside external' => [['volumes' => ['data' => ['external' => ['name' => 'victim-data']]]], 'Volume "data" must belong to this resource.'],
    'declared volume with a foreign name' => [['services' => ['app' => ['image' => 'alpine', 'volumes' => ['data:/data']]], 'volumes' => ['data' => ['name' => 'victim-data']]], 'Service "app": volume "data" must belong to this resource.'],
]);

it('accepts extension keys, disabled options, remote builds and keeps only a coherent memory reservation', function () {
    $services = hardenCompose(['services' => [
        'web' => [
            'image' => 'nginx', 'x-coolify' => ['note' => 'mine'],
            'privileged' => false, 'devices' => [], 'gpus' => null, 'oom_kill_disable' => 'false',
            'mem_limit' => '1g', 'mem_reservation' => '8g',
            'build' => ['context' => 'https://github.com/acme/web.git#main', 'additional_contexts' => [
                'base' => 'docker-image://alpine:3', 'deps' => 'service:worker', 'assets' => './assets',
            ]],
        ],
        'worker' => ['image' => 'busybox', 'mem_limit' => '1g', 'mem_reservation' => '256m', 'build' => 'git@github.com:acme/worker.git'],
    ]])['compose']['services'];

    expect($services['web'])->not->toHaveKeys(['privileged', 'devices', 'gpus', 'oom_kill_disable', 'mem_reservation'])
        ->and($services['web']['x-coolify'])->toBe(['note' => 'mine'])
        ->and($services['web']['mem_limit'])->toBe('1024m')
        ->and($services['worker']['mem_reservation'])->toBe('256m');
});

it('keeps the log drain of the platform and bounds every other logging driver', function () {
    $services = hardenCompose(['services' => [
        'drained' => ['image' => 'nginx', 'logging' => generate_fluentd_configuration()],
        'elsewhere' => ['image' => 'nginx', 'logging' => ['driver' => 'fluentd', 'options' => ['fluentd-address' => 'tcp://10.0.0.5:24224']]],
        'syslog' => ['image' => 'nginx', 'logging' => ['driver' => 'syslog', 'options' => ['syslog-address' => 'udp://10.0.0.5:514']]],
    ]])['compose']['services'];

    expect($services['drained']['logging'])->toBe(generate_fluentd_configuration())
        ->and($services['elsewhere']['logging']['driver'])->toBe('json-file')
        ->and($services['syslog']['logging'])->toBe(['driver' => 'json-file', 'options' => ['max-size' => '10m', 'max-file' => '3']]);
});

it('drops host ports it can not read, and pins the proxy only to a network of the resource', function () {
    $result = hardenCompose(['services' => [
        'web' => ['image' => 'nginx', 'ports' => [['target' => '${PORT}'], null]],
        'site' => ['image' => 'nginx', 'networks' => ['site'], 'labels' => ['traefik.enable=true']],
    ], 'networks' => ['site' => null]]);

    expect($result['compose']['services']['web'])->not->toHaveKeys(['ports', 'expose'])
        ->and($result['notices'])->toHaveCount(1)
        ->and(collect($result['compose']['services']['site']['labels'])->filter(fn ($label) => str_starts_with($label, 'traefik.docker.network=')))->toHaveCount(0);
});

it('parses memory units up to terabytes', function () {
    $policy = new TenantContainerPolicy(platformContext());

    expect($policy->memoryToMb('1t'))->toBe(1048576)
        ->and($policy->memoryToMb('2048k'))->toBe(2)
        ->and($policy->memoryToMb('1048576b'))->toBe(1);
});
