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
        Schema::create('platform_tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained('teams')->cascadeOnDelete();
            $table->string('plan')->nullable();
            $table->string('billing_interval')->nullable();
            $table->foreignId('platform_region_id')->nullable()->constrained('platform_regions')->nullOnDelete();
            $table->string('status')->default('pending')->index();
            $table->json('quota_overrides')->nullable();
            $table->json('stopped_resources')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->timestamp('past_due_since')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('hard_suspended_at')->nullable();
            $table->timestamp('quota_warning_notified_at')->nullable();
            $table->timestamp('quota_exceeded_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_tenants');
    }
};
