<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments move from Banco Inter to Efí Bank. Customers and invoices of the old provider become
 * Efí ones; open invoices that had a charge at the old bank lose it and are issued again through
 * Efí by the next billing cycle. The old credentials are removed, and an owner who had chosen the
 * old provider in the console now has Efí.
 */
return new class extends Migration
{
    private const OLD_PROVIDER = 'inter';

    private const NEW_PROVIDER = 'efi';

    public function up(): void
    {
        if (Schema::hasTable('platform_tenants') && Schema::hasColumn('platform_tenants', 'billing_provider')) {
            DB::table('platform_tenants')->where('billing_provider', self::OLD_PROVIDER)->update(['billing_provider' => self::NEW_PROVIDER]);
        }

        if (Schema::hasTable('platform_invoices') && Schema::hasColumn('platform_invoices', 'provider')) {
            DB::table('platform_invoices')
                ->where('provider', self::OLD_PROVIDER)
                ->where('status', 'open')
                ->whereNotNull('provider_reference')
                ->orderBy('id')
                ->get(['id', 'provider_reference', 'metadata'])
                ->each(function (object $invoice) {
                    $metadata = json_decode((string) $invoice->metadata, true);
                    $metadata = is_array($metadata) ? $metadata : [];
                    $metadata['previous_provider'] = self::OLD_PROVIDER;
                    $metadata['previous_reference'] = (string) $invoice->provider_reference;
                    unset($metadata['rail'], $metadata['issue_error'], $metadata['issue_attempts'], $metadata['notified_at']);
                    DB::table('platform_invoices')->where('id', $invoice->id)->update([
                        'provider_reference' => null,
                        'provider_status' => null,
                        'payment_details' => null,
                        'synced_at' => null,
                        'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ]);
                });
            DB::table('platform_invoices')->where('provider', self::OLD_PROVIDER)->update(['provider' => self::NEW_PROVIDER]);
        }

        if (Schema::hasTable('platform_settings')) {
            DB::table('platform_settings')->where('key', 'like', self::OLD_PROVIDER.'.%')->delete();
            $override = DB::table('platform_settings')->where('key', 'config:platform.billing.provider')->first(['id', 'value']);
            if ($override) {
                try {
                    $provider = json_decode(Crypt::decryptString((string) $override->value), true);
                } catch (\Throwable) {
                    $provider = null;
                }
                if ($provider === self::OLD_PROVIDER) {
                    DB::table('platform_settings')->where('id', $override->id)->update([
                        'value' => Crypt::encryptString(json_encode(self::NEW_PROVIDER)),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // The old provider is gone: there is nothing to go back to.
    }
};
