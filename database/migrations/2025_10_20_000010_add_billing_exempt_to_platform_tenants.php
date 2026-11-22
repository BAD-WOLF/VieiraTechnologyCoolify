<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts the owner keeps without charging (own projects, partners, courtesy periods): their
 * plan renews without invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_tenants', function (Blueprint $table) {
            $table->boolean('billing_exempt')->default(false);
            $table->text('operator_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_tenants', function (Blueprint $table) {
            $table->dropColumn(['billing_exempt', 'operator_notes']);
        });
    }
};
