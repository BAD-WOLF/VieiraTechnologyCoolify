<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Re-encrypts every encrypted column (environment variables, private keys, S3 and Git
 * credentials, notification secrets, billing data...) with the current APP_KEY, reading the old
 * values with APP_PREVIOUS_KEYS (wiki 9, "Gestão de Credenciais": rotation of the credentials).
 * The columns are found from the "encrypted" casts of the models, so new upstream models are
 * covered too. Rows are updated with the query builder: no model events run.
 */
class PlatformRotateAppKey extends Command
{
    protected $signature = 'platform:rotate-app-key {--dry-run : Only count what would be re-encrypted}';

    protected $description = 'Re-encrypt every encrypted column with the current APP_KEY (old values are read with APP_PREVIOUS_KEYS).';

    public function handle(): int
    {
        if (blank(config('app.previous_keys')) && ! $this->option('dry-run')) {
            $this->warn('APP_PREVIOUS_KEYS is empty: values encrypted with an older key can not be read.');
        }
        $rows = [];
        $failures = 0;
        foreach (self::encryptedColumns() as $table => ['key' => $keyName, 'columns' => $columns]) {
            $done = 0;
            $broken = 0;
            DB::table($table)->select(array_merge([$keyName], $columns))->orderBy($keyName)
                ->chunk(500, function ($records) use ($table, $keyName, $columns, &$done, &$broken) {
                    foreach ($records as $record) {
                        $changes = [];
                        foreach ($columns as $column) {
                            $value = $record->{$column};
                            if (! is_string($value) || $value === '') {
                                continue;
                            }
                            try {
                                $plain = Crypt::decryptString($value);
                            } catch (DecryptException) {
                                $broken++;

                                continue;
                            }
                            $changes[$column] = Crypt::encryptString($plain);
                        }
                        if ($changes !== []) {
                            $done += count($changes);
                            if (! $this->option('dry-run')) {
                                DB::table($table)->where($keyName, $record->{$keyName})->update($changes);
                            }
                        }
                    }
                });
            $failures += $broken;
            $rows[] = [$table, implode(', ', $columns), $done, $broken];
        }
        $this->table(['Table', 'Columns', $this->option('dry-run') ? 'Would re-encrypt' : 'Re-encrypted', 'Unreadable'], $rows);
        if ($failures > 0) {
            $this->error("{$failures} value(s) could not be decrypted with APP_KEY nor APP_PREVIOUS_KEYS and were left as they are.");

            return self::FAILURE;
        }
        if (! $this->option('dry-run')) {
            $this->info('Done. Remove APP_PREVIOUS_KEYS and restart the control plane.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{key: string, columns: list<string>}>
     */
    public static function encryptedColumns(): array
    {
        $tables = [];
        foreach (File::allFiles(app_path('Models')) as $file) {
            $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            /** @var Model $model */
            $model = new $class;
            $columns = array_keys(array_filter($model->getCasts(), fn ($cast) => is_string($cast) && str_starts_with($cast, 'encrypted')));
            $table = $model->getTable();
            if ($columns === [] || ! Schema::hasTable($table)) {
                continue;
            }
            $columns = array_values(array_filter($columns, fn ($column) => Schema::hasColumn($table, $column)));
            if ($columns === []) {
                continue;
            }
            $tables[$table] = [
                'key' => $model->getKeyName(),
                'columns' => array_values(array_unique(array_merge($tables[$table]['columns'] ?? [], $columns))),
            ];
        }
        ksort($tables);

        return $tables;
    }
}
