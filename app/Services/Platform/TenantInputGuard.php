<?php

namespace App\Services\Platform;

use App\Exceptions\TenantInputRejected;
use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\EnvironmentVariable;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Rules\ValidGitBranch;
use App\Rules\ValidGitRepositoryUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Field-level allowlists for tenant data that ends up in shell commands or host paths on the
 * shared nodes (build commands, paths, container names, database credentials, backup options...).
 * Upstream Coolify trusts these values because the user owns the server; on a shared node they
 * would allow command injection as root, so tenants get strict but practical character sets.
 */
class TenantInputGuard
{
    public const PATTERN_IMAGE = '/^[A-Za-z0-9][A-Za-z0-9._\-\/:@]{0,254}$/';

    /**
     * Images of compose services, which may use compose variables ("ghcr.io/org/app:${TAG:-1.2}"):
     * they are only written to the compose file, never to a shell command.
     */
    public const PATTERN_SERVICE_IMAGE = '/^(?=.{1,255}$)(?=[A-Za-z0-9$])(?:[A-Za-z0-9._\-\/:@]|\$\{[A-Za-z_][A-Za-z0-9_]*(?::?[-?+][A-Za-z0-9._\-\/:@]*)?\})+$/';

    public const PATTERN_TAG = '/^[A-Za-z0-9_][A-Za-z0-9._\-]{0,127}$/';

    public const PATTERN_NAME = '/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,127}$/';

    public const PATTERN_DB_IDENTIFIER = '/^[A-Za-z0-9_][A-Za-z0-9_\-]{0,62}$/';

    public const PATTERN_DB_SECRET = '/^[A-Za-z0-9_\-.]{8,256}$/';

    public const PATTERN_MEMORY = '/^(0|\d+(\.\d+)?\s*[bkmgBKMG]?[bB]?)$/';

    public const PATTERN_CPUS = '/^\d+(\.\d+)?$/';

    public const PATTERN_PORTS_EXPOSES = '/^\d{1,5}(,\d{1,5})*$/';

    public const PATTERN_COMMIT = '/^([a-fA-F0-9]{4,40}|HEAD)$/';

    /**
     * Commands passed to build tools: anything printable except characters that break shell quoting.
     */
    public const PATTERN_BUILD_COMMAND = '/^[^\x00-\x08\x0B-\x1F\x7F\'`]*$/';

    /**
     * @throws ValidationException
     */
    public static function validate(Model $model): void
    {
        $errors = [];
        foreach (self::rulesFor($model) as $field => $rule) {
            if ($model->exists && ! $model->isDirty($field)) {
                continue;
            }
            $value = $model->getAttribute($field);
            if (is_null($value) || $value === '') {
                continue;
            }
            $message = $rule($value, $model);
            if (is_string($message)) {
                $errors[$field][] = $message;
            }
        }
        if (count($errors) > 0) {
            throw TenantInputRejected::withMessages($errors);
        }
    }

    /**
     * @return array<string, callable(mixed, Model): ?string>
     */
    public static function rulesFor(Model $model): array
    {
        return match (true) {
            $model instanceof Application => self::applicationRules(),
            $model instanceof ApplicationSetting => self::applicationSettingRules(),
            $model instanceof StandalonePostgresql => self::databaseRules(['postgres_user' => 'identifier', 'postgres_password' => 'secret', 'postgres_db' => 'identifier']),
            $model instanceof StandaloneMysql => self::databaseRules(['mysql_root_password' => 'secret', 'mysql_user' => 'identifier', 'mysql_password' => 'secret', 'mysql_database' => 'identifier']),
            $model instanceof StandaloneMariadb => self::databaseRules(['mariadb_root_password' => 'secret', 'mariadb_user' => 'identifier', 'mariadb_password' => 'secret', 'mariadb_database' => 'identifier']),
            $model instanceof StandaloneMongodb => self::databaseRules(['mongo_initdb_root_username' => 'identifier', 'mongo_initdb_root_password' => 'secret', 'mongo_initdb_database' => 'identifier']),
            $model instanceof StandaloneRedis => self::databaseRules(['redis_username' => 'identifier', 'redis_password' => 'secret']),
            $model instanceof StandaloneKeydb => self::databaseRules(['keydb_password' => 'secret']),
            $model instanceof StandaloneDragonfly => self::databaseRules(['dragonfly_password' => 'secret']),
            $model instanceof StandaloneClickhouse => self::databaseRules(['clickhouse_admin_user' => 'identifier', 'clickhouse_admin_password' => 'secret']),
            $model instanceof ServiceApplication, $model instanceof ServiceDatabase => [
                'image' => self::regex(self::PATTERN_SERVICE_IMAGE, 'Invalid image name.'),
                'public_port' => fn ($value) => self::publicPort($value),
            ],
            $model instanceof EnvironmentVariable => [
                'key' => self::regex('/^[A-Za-z_][A-Za-z0-9_.\-]{0,255}$/', 'Variable names may only contain letters, numbers, "_", "-" and ".".'),
            ],
            $model instanceof ScheduledTask => [
                'container' => self::regex(self::PATTERN_NAME, 'Invalid container name.'),
            ],
            $model instanceof ScheduledDatabaseBackup => [
                'databases_to_backup' => fn ($value, $backup) => self::databasesToBackup((string) $value, $backup),
            ],
            $model instanceof LocalFileVolume => [
                'fs_path' => fn ($value, $volume) => self::hostPath((string) $value, $volume),
                'mount_path' => fn ($value) => self::containerPath((string) $value),
            ],
            $model instanceof LocalPersistentVolume => [
                'name' => fn ($value, $volume) => self::volumeName((string) $value, $volume),
                'host_path' => fn ($value, $volume) => self::hostPath((string) $value, $volume),
                'mount_path' => fn ($value) => self::containerPath((string) $value),
            ],
            $model instanceof S3Storage => [
                'endpoint' => fn ($value) => self::publicEndpoint((string) $value),
                'bucket' => self::regex('/^[A-Za-z0-9][A-Za-z0-9.\-_]{1,62}$/', 'Invalid bucket name.'),
                'region' => self::regex('/^[A-Za-z0-9\-_]{1,64}$/', 'Invalid region.'),
                'key' => self::regex('/^[A-Za-z0-9+\/=_\-.]{1,256}$/', 'Invalid access key.'),
                'secret' => self::regex('/^[A-Za-z0-9+\/=_\-.]{1,256}$/', 'Invalid secret key.'),
            ],
            default => [],
        };
    }

    /**
     * @return array<string, callable(mixed, Model): ?string>
     */
    private static function applicationRules(): array
    {
        $buildCommand = self::regex(self::PATTERN_BUILD_COMMAND, 'Commands can not contain single quotes, backticks or control characters.');

        return [
            'git_repository' => fn ($value) => self::gitRepository((string) $value),
            'git_branch' => fn ($value) => self::rule($value, new ValidGitBranch, 'branch'),
            'git_commit_sha' => self::regex(self::PATTERN_COMMIT, 'Invalid commit SHA.'),
            'base_directory' => fn ($value) => PathPolicy::isSafeInnerPath((string) $value) ? null : 'Invalid base directory.',
            'publish_directory' => fn ($value) => PathPolicy::isSafeInnerPath((string) $value) ? null : 'Invalid publish directory.',
            'dockerfile_location' => fn ($value) => PathPolicy::isSafeInnerPath((string) $value) ? null : 'Invalid Dockerfile location.',
            'docker_compose_location' => fn ($value) => PathPolicy::isSafeInnerPath((string) $value) ? null : 'Invalid docker compose location.',
            'dockerfile_target_build' => self::regex(self::PATTERN_NAME, 'Invalid build target.'),
            'build_command' => $buildCommand,
            'install_command' => $buildCommand,
            'start_command' => $buildCommand,
            'docker_compose_custom_start_command' => fn () => 'Custom docker compose commands are not available on the platform.',
            'docker_compose_custom_build_command' => fn () => 'Custom docker compose commands are not available on the platform.',
            'ports_exposes' => self::regex(self::PATTERN_PORTS_EXPOSES, 'Ports must be a comma separated list of numbers.'),
            'ports_mappings' => fn () => 'Publishing host ports is not available on the platform; expose the application through a domain.',
            'health_check_path' => self::regex('#^/[A-Za-z0-9._~/\-?=&%:+]*$#', 'Invalid health check path.'),
            'health_check_host' => self::regex('/^[A-Za-z0-9.\-]{1,253}$/', 'Invalid health check host.'),
            'health_check_method' => self::regex('/^(GET|POST|HEAD|OPTIONS)$/', 'Invalid health check method.'),
            'health_check_scheme' => self::regex('/^https?$/', 'Invalid health check scheme.'),
            'health_check_port' => self::regex('/^\d{1,5}$/', 'Invalid health check port.'),
            'health_check_response_text' => self::regex('/^[A-Za-z0-9 _.\-:\/]{0,255}$/', 'Invalid health check response text.'),
            'docker_registry_image_name' => self::regex(self::PATTERN_IMAGE, 'Invalid image name.'),
            'docker_registry_image_tag' => self::regex(self::PATTERN_TAG, 'Invalid image tag.'),
            'static_image' => self::regex(self::PATTERN_IMAGE, 'Invalid static image.'),
            'pre_deployment_command_container' => self::regex(self::PATTERN_NAME, 'Invalid container name.'),
            'post_deployment_command_container' => self::regex(self::PATTERN_NAME, 'Invalid container name.'),
            'custom_docker_run_options' => fn ($value) => self::dockerRunOptions((string) $value),
            'limits_memory' => self::regex(self::PATTERN_MEMORY, 'Invalid memory limit.'),
            'limits_memory_swap' => self::regex(self::PATTERN_MEMORY, 'Invalid swap limit.'),
            'limits_memory_reservation' => self::regex(self::PATTERN_MEMORY, 'Invalid memory reservation.'),
            'limits_memory_swappiness' => self::regex('/^\d{1,3}$/', 'Invalid swappiness.'),
            'limits_cpus' => self::regex(self::PATTERN_CPUS, 'Invalid CPU limit.'),
            'limits_cpuset' => self::regex('/^[0-9,\-]*$/', 'Invalid cpuset.'),
            'limits_cpu_shares' => self::regex('/^\d{1,6}$/', 'Invalid CPU shares.'),
            'custom_network_aliases' => fn ($value) => self::networkAliases($value),
        ];
    }

    /**
     * @return array<string, callable(mixed, Model): ?string>
     */
    private static function applicationSettingRules(): array
    {
        $deny = fn (string $message) => fn ($value) => $value ? $message : null;

        return [
            'is_raw_compose_deployment_enabled' => $deny('Raw docker compose deployments are not available on the platform.'),
            'is_gpu_enabled' => $deny('GPUs are not available on the platform.'),
            'custom_internal_name' => fn () => 'Custom container names are not available on the platform.',
            'is_build_server_enabled' => $deny('Build servers are managed by the platform.'),
        ];
    }

    /**
     * @param  array<string, string>  $credentials  field => identifier|secret
     * @return array<string, callable(mixed, Model): ?string>
     */
    private static function databaseRules(array $credentials): array
    {
        $rules = [
            'image' => self::regex(self::PATTERN_IMAGE, 'Invalid image name.'),
            'public_port' => fn ($value) => self::publicPort($value),
            'ports_mappings' => fn () => 'Publishing host ports is not available on the platform; use the public port option instead.',
            'custom_docker_run_options' => fn ($value) => self::dockerRunOptions((string) $value),
            'limits_memory' => self::regex(self::PATTERN_MEMORY, 'Invalid memory limit.'),
            'limits_memory_swap' => self::regex(self::PATTERN_MEMORY, 'Invalid swap limit.'),
            'limits_memory_reservation' => self::regex(self::PATTERN_MEMORY, 'Invalid memory reservation.'),
            'limits_memory_swappiness' => self::regex('/^\d{1,3}$/', 'Invalid swappiness.'),
            'limits_cpus' => self::regex(self::PATTERN_CPUS, 'Invalid CPU limit.'),
            'limits_cpuset' => self::regex('/^[0-9,\-]*$/', 'Invalid cpuset.'),
            'limits_cpu_shares' => self::regex('/^\d{1,6}$/', 'Invalid CPU shares.'),
            'init_scripts' => fn ($value) => self::initScripts($value),
        ];
        foreach ($credentials as $field => $kind) {
            $rules[$field] = $kind === 'secret'
                ? self::regex(self::PATTERN_DB_SECRET, 'Passwords must have 8+ characters: letters, numbers, "_", "-" or ".".')
                : self::regex(self::PATTERN_DB_IDENTIFIER, 'Names may only contain letters, numbers, "_" and "-".');
        }

        return $rules;
    }

    private static function regex(string $pattern, string $message): callable
    {
        return fn ($value) => preg_match($pattern, (string) $value) ? null : $message;
    }

    private static function rule(mixed $value, object $rule, string $attribute = 'value'): ?string
    {
        $validator = Validator::make(['value' => $value], ['value' => [$rule]], [], ['value' => $attribute]);

        return $validator->fails() ? $validator->errors()->first('value') : null;
    }

    /**
     * Applications of a GitHub or GitLab source keep "owner/repository" (GitLab groups may nest);
     * the other sources keep the URL used to clone.
     */
    public static function gitRepository(string $value): ?string
    {
        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9_.\-]*(/[A-Za-z0-9][A-Za-z0-9_.\-]*)+$#', $value)) {
            return null;
        }

        return self::rule($value, new ValidGitRepositoryUrl, 'repository');
    }

    public static function publicPort(mixed $value): ?string
    {
        if (! preg_match('/^\d{1,5}$/', (string) $value)) {
            return 'Invalid public port.';
        }
        $port = (int) $value;
        $min = (int) config('platform.container.public_port_min', 20000);
        $max = (int) config('platform.container.public_port_max', 29999);
        if ($port < $min || $port > $max) {
            return "Public ports must be between {$min} and {$max}.";
        }

        return null;
    }

    /**
     * Only harmless docker run flags; everything that changes isolation is refused.
     */
    public static function dockerRunOptions(string $options): ?string
    {
        if (trim($options) === '') {
            return null;
        }
        if (preg_match('/[;&|`$<>\\\\\'"\n\r]/', $options)) {
            return 'Custom docker options contain forbidden characters.';
        }
        $allowed = ['--init', '--ulimit', '--shm-size', '--hostname', '--cap-drop', '--tmpfs', '--read-only', '--stop-timeout', '--stop-signal'];
        preg_match_all('/(--[a-z][a-z0-9\-]*)/', $options, $matches);
        foreach ($matches[1] as $flag) {
            if (! in_array($flag, $allowed, true)) {
                return "Docker option {$flag} is not available on the platform.";
            }
        }

        return null;
    }

    private static function containerPath(string $path): ?string
    {
        if (! str_starts_with($path, '/') || ! PathPolicy::isSafeInnerPath($path, false)) {
            return 'Invalid mount path.';
        }

        return null;
    }

    private static function hostPath(string $path, Model $volume): ?string
    {
        $resource = class_exists((string) $volume->resource_type) ? $volume->resource_type::find($volume->resource_id) : null;
        $roots = PathPolicy::rootsFor($resource);
        if (! PathPolicy::isWithin($path, $roots)) {
            return 'Host paths must stay inside the resource directory on the platform. Use a volume instead.';
        }

        return null;
    }

    private static function volumeName(string $name, Model $volume): ?string
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,254}$/', $name)) {
            return 'Invalid volume name.';
        }
        $resource = class_exists((string) $volume->resource_type) ? $volume->resource_type::find($volume->resource_id) : null;
        if ($resource instanceof ServiceApplication || $resource instanceof ServiceDatabase) {
            $resource = $resource->service;
        }
        $uuid = (string) data_get($resource, 'uuid');
        if ($uuid === '' || ! str_contains($name, $uuid)) {
            return 'Volume names must contain the resource UUID.';
        }

        return null;
    }

    private static function databasesToBackup(string $value, Model $backup): ?string
    {
        $type = (string) data_get($backup, 'database_type');
        $pattern = str_contains(strtolower($type), 'mongo') ? '/^[A-Za-z0-9_\-.,:| ]*$/' : '/^[A-Za-z0-9_\-., ]*$/';

        return preg_match($pattern, $value) ? null : 'Database names may only contain letters, numbers, "_", "-" and "." separated by commas.';
    }

    private static function initScripts(mixed $value): ?string
    {
        foreach ((array) $value as $script) {
            if (! PathPolicy::isSafeFilename((string) data_get($script, 'filename'))) {
                return 'Init script file names may only contain letters, numbers, "_", "-" and ".".';
            }
        }

        return null;
    }

    private static function networkAliases(mixed $value): ?string
    {
        foreach ((array) $value as $alias) {
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,62}$/', (string) $alias)) {
                return 'Invalid network alias.';
            }
        }

        return null;
    }

    /**
     * S3 endpoints are contacted from the control plane and the nodes: refuse private addresses (SSRF).
     */
    public static function publicEndpoint(string $endpoint): ?string
    {
        if (! preg_match('#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?(/[A-Za-z0-9._\-/]*)?$#', $endpoint)) {
            return 'Invalid endpoint URL.';
        }
        $host = (string) parse_url($endpoint, PHP_URL_HOST);
        if (! self::isPublicHost($host)) {
            return 'The endpoint must be a public address.';
        }

        return null;
    }

    public static function isPublicHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            return false;
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
