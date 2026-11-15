<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Operator settings of the platform (e.g. Banco Inter credentials), encrypted at rest with APP_KEY.
 */
class PlatformSetting extends Model
{
    protected $guarded = [];

    /** @var array<string, string|null> */
    private static array $memo = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (! array_key_exists($key, self::$memo)) {
            $stored = self::query()->where('key', $key)->value('value');
            self::$memo[$key] = is_null($stored) ? null : self::decrypt($stored);
        }

        return self::$memo[$key] ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        if (blank($value)) {
            self::query()->where('key', $key)->delete();
            self::$memo[$key] = null;

            return;
        }
        self::query()->updateOrCreate(['key' => $key], ['value' => Crypt::encryptString($value)]);
        self::$memo[$key] = $value;
    }

    public static function flushMemo(): void
    {
        self::$memo = [];
    }

    private static function decrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
