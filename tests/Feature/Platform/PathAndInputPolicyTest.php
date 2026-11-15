<?php

use App\Services\Platform\DomainPolicy;
use App\Services\Platform\PathPolicy;
use App\Services\Platform\TenantInputGuard;

it('normalizes absolute paths without touching the filesystem', function (string $path, ?string $expected) {
    expect(PathPolicy::normalize($path))->toBe($expected);
})->with([
    ['/data/coolify/applications/x/data', '/data/coolify/applications/x/data'],
    ['/data//coolify/./applications/x/', '/data/coolify/applications/x'],
    ['/data/coolify/applications/x/../y', '/data/coolify/applications/y'],
    ['/../etc', null],
    ['relative/path', null],
    ['/data/$(id)', null],
    ["/data/x\n/etc", null],
    ['/data/x;rm -rf /', null],
]);

it('confines paths to the resource roots', function () {
    $roots = ['/data/coolify/applications/abc'];

    expect(PathPolicy::isWithin('/data/coolify/applications/abc', $roots))->toBeTrue()
        ->and(PathPolicy::isWithin('/data/coolify/applications/abc/conf/nginx.conf', $roots))->toBeTrue()
        ->and(PathPolicy::isWithin('/data/coolify/applications/abcdef', $roots))->toBeFalse()
        ->and(PathPolicy::isWithin('/data/coolify/applications/abc/../def', $roots))->toBeFalse()
        ->and(PathPolicy::isWithin('/etc/passwd', $roots))->toBeFalse()
        ->and(PathPolicy::isWithin('/', ['/']))->toBeFalse();
    expect(PathPolicy::resolve('./data', '/data/coolify/applications/abc'))->toBe('/data/coolify/applications/abc/data')
        ->and(PathPolicy::resolve('../other', '/data/coolify/applications/abc'))->toBe('/data/coolify/applications/other')
        ->and(PathPolicy::resolve('~/x', '/data/coolify/applications/abc'))->toBeNull();
});

it('accepts only simple file names', function () {
    expect(PathPolicy::isSafeFilename('01-init.sql'))->toBeTrue()
        ->and(PathPolicy::isSafeFilename('../../etc/cron.d/x'))->toBeFalse()
        ->and(PathPolicy::isSafeFilename('x; curl evil|sh'))->toBeFalse()
        ->and(PathPolicy::isSafeFilename('..'))->toBeFalse()
        ->and(PathPolicy::isSafeInnerPath('/apps/web/Dockerfile'))->toBeTrue()
        ->and(PathPolicy::isSafeInnerPath('/Dockerfile -t evil'))->toBeFalse()
        ->and(PathPolicy::isSafeInnerPath('/../../etc'))->toBeFalse();
});

it('only allows harmless docker run options', function () {
    expect(TenantInputGuard::dockerRunOptions('--init --ulimit nofile=1024:2048 --shm-size=256m'))->toBeNull()
        ->and(TenantInputGuard::dockerRunOptions('--privileged'))->toContain('--privileged')
        ->and(TenantInputGuard::dockerRunOptions('--cap-add SYS_ADMIN'))->toContain('--cap-add')
        ->and(TenantInputGuard::dockerRunOptions('--device /dev/kvm'))->toContain('--device')
        ->and(TenantInputGuard::dockerRunOptions('--init; rm -rf /'))->toContain('forbidden characters');
});

it('restricts public database ports to the platform range', function () {
    config(['platform.container.public_port_min' => 20000, 'platform.container.public_port_max' => 29999]);

    expect(TenantInputGuard::publicPort(25432))->toBeNull()
        ->and(TenantInputGuard::publicPort(22))->toContain('between')
        ->and(TenantInputGuard::publicPort('5432; id'))->toBe('Invalid public port.');
});

it('refuses private S3 endpoints', function () {
    expect(TenantInputGuard::publicEndpoint('https://8.8.8.8'))->toBeNull()
        ->and(TenantInputGuard::publicEndpoint('http://169.254.169.254/latest'))->not->toBeNull()
        ->and(TenantInputGuard::publicEndpoint('http://10.0.0.5:9000'))->not->toBeNull()
        ->and(TenantInputGuard::publicEndpoint('http://localhost:9000'))->not->toBeNull()
        ->and(TenantInputGuard::publicEndpoint('http://s3.local:9000'))->not->toBeNull()
        ->and(TenantInputGuard::publicEndpoint('https://x.com"; id'))->toBe('Invalid endpoint URL.');
});

it('extracts hosts from fqdn lists and compose domains', function () {
    expect(DomainPolicy::hostsFromFqdnList('https://App.Example.com,http://www.example.com:8080/api'))->toBe(['app.example.com', 'www.example.com'])
        ->and(DomainPolicy::hostsFromComposeDomains(json_encode(['web' => ['domain' => 'https://a.com,https://b.com'], 'api' => ['domain' => '']])))->toBe(['a.com', 'b.com'])
        ->and(DomainPolicy::hostOf('example.com/path'))->toBe('example.com');
});

it('classifies platform and customer hosts', function () {
    config(['platform.base_domain' => 'apps.example.com']);

    expect(DomainPolicy::baseDomain())->toBe('apps.example.com')
        ->and(DomainPolicy::isCustomHost('x.apps.example.com'))->toBeFalse()
        ->and(DomainPolicy::isCustomHost('apps.example.com'))->toBeFalse()
        ->and(DomainPolicy::isCustomHost('shop.com'))->toBeTrue()
        ->and(DomainPolicy::isCustomHost('evilapps.example.com'))->toBeTrue();
});
