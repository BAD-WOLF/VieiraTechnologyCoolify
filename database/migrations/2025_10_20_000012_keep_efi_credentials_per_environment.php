<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Efí credentials are kept per environment (Produção, Homologação): the ones saved before belong to
 * the environment that was in use when they were saved. A value already saved for that
 * environment wins over the old one.
 */
return new class extends Migration
{
    private const CREDENTIALS = ['client_id', 'client_secret', 'certificate', 'private_key', 'pix_key'];

    public function up(): void
    {
        if (! Schema::hasTable('platform_settings')) {
            return;
        }
        $environment = $this->environment();
        foreach (self::CREDENTIALS as $key) {
            $this->move("efi.{$key}", "efi.{$environment}.{$key}");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_settings')) {
            return;
        }
        $environment = $this->environment();
        foreach (self::CREDENTIALS as $key) {
            $this->move("efi.{$environment}.{$key}", "efi.{$key}");
        }
        DB::table('platform_settings')->where('key', 'like', 'efi.production.%')->orWhere('key', 'like', 'efi.sandbox.%')->delete();
    }

    private function move(string $from, string $to): void
    {
        $row = DB::table('platform_settings')->where('key', $from)->first();
        if (is_null($row)) {
            return;
        }
        if (! DB::table('platform_settings')->where('key', $to)->exists()) {
            DB::table('platform_settings')->insert(['key' => $to, 'value' => $row->value, 'created_at' => $row->created_at ?? now(), 'updated_at' => now()]);
        }
        DB::table('platform_settings')->where('key', $from)->delete();
    }

    private function environment(): string
    {
        $stored = DB::table('platform_settings')->where('key', 'efi.environment')->value('value');
        try {
            $value = is_null($stored) ? null : Crypt::decryptString((string) $stored);
        } catch (\Throwable) {
            $value = null;
        }

        return ($value ?? config('platform.efi.environment')) === 'sandbox' ? 'sandbox' : 'production';
    }
};
