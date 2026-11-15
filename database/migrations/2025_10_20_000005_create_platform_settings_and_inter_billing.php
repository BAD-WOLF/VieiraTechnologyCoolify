<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::table('platform_tenants', function (Blueprint $table) {
            $table->string('billing_provider')->nullable();
            $table->text('billing_profile')->nullable();
            $table->string('pending_plan')->nullable();
            $table->string('pending_interval')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
        });

        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->string('number')->nullable()->unique();
            $table->string('provider')->nullable()->index();
            $table->string('plan')->nullable();
            $table->string('billing_interval')->nullable();
            $table->string('provider_reference')->nullable()->index();
            $table->string('provider_status')->nullable();
            $table->date('due_date')->nullable();
            $table->bigInteger('amount_paid_cents')->nullable();
            $table->string('payment_method')->nullable();
            $table->json('payment_details')->nullable();
            $table->timestamp('synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropIndex(['provider']);
            $table->dropIndex(['provider_reference']);
            $table->dropColumn(['number', 'provider', 'plan', 'billing_interval', 'provider_reference', 'provider_status', 'due_date', 'amount_paid_cents', 'payment_method', 'payment_details', 'synced_at']);
        });

        Schema::table('platform_tenants', function (Blueprint $table) {
            $table->dropColumn(['billing_provider', 'billing_profile', 'pending_plan', 'pending_interval', 'cancel_at_period_end']);
        });

        Schema::dropIfExists('platform_settings');
    }
};
