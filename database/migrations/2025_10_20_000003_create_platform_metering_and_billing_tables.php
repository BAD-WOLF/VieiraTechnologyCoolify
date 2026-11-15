<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->string('region')->nullable();
            $table->string('metric');
            $table->double('value');
            $table->timestamp('recorded_at');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['team_id', 'metric', 'recorded_at']);
            $table->index('recorded_at');
        });

        Schema::create('aggregated_usage_hourly', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('metric');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->double('value');
            $table->unsignedInteger('samples')->default(0);
            $table->timestamps();

            $table->unique(['team_id', 'metric', 'period_start']);
            $table->index('period_start');
        });

        Schema::create('platform_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('type')->default('usage');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->bigInteger('amount_cents')->default(0);
            $table->string('currency', 8);
            $table->string('status')->default('draft')->index();
            $table->json('lines')->nullable();
            $table->string('stripe_invoice_id')->nullable()->index();
            $table->string('stripe_hosted_invoice_url')->nullable();
            $table->json('stripe_invoice_item_ids')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'type', 'period_start']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('actor_type')->default('system');
            $table->string('action')->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('platform_invoices');
        Schema::dropIfExists('aggregated_usage_hourly');
        Schema::dropIfExists('usage_events');
    }
};
