<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Services\Platform\PlatformEvents;
use App\Services\Platform\PlatformGate;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Before the fix of the audit listeners, a listener of the platform stopped the "created" event
 * of applications and databases, so Coolify did not create the settings of new applications nor
 * the data volume of new databases. This command finds those resources and replays the
 * "created" listeners of Coolify for them.
 */
class PlatformRepairResources extends Command
{
    protected $signature = 'platform:repair-resources {--fix : Create the missing settings and data volumes}';

    protected $description = 'Find applications without settings and databases without a data volume, and repair them.';

    public function handle(): int
    {
        /** @var list<Model> $broken */
        $broken = [];
        Application::query()->whereDoesntHave('settings')->get()->each(function (Application $application) use (&$broken) {
            $broken[] = $application;
        });
        foreach (PlatformGate::databaseModels() as $model) {
            $model::query()->whereDoesntHave('persistentStorages')->get()->each(function (Model $database) use (&$broken) {
                $broken[] = $database;
            });
        }
        if ($broken === []) {
            $this->info('Every application has its settings and every database its data volume.');

            return self::SUCCESS;
        }
        $this->table(['Type', 'UUID', 'Name', 'Team'], array_map(fn (Model $resource) => [
            class_basename($resource),
            $resource->getAttribute('uuid'),
            $resource->getAttribute('name'),
            data_get($resource, 'environment.project.team_id'),
        ], $broken));
        if (! $this->option('fix')) {
            $this->warn('Run again with --fix to repair them. Back up the databases first: the new data volume is mounted on the next restart, and data written only inside the container is not in it.');

            return self::FAILURE;
        }
        foreach ($broken as $resource) {
            PlatformEvents::muted(fn () => event('eloquent.created: '.$resource::class, $resource));
        }
        $this->info(count($broken).' resource(s) repaired.');

        return self::SUCCESS;
    }
}
