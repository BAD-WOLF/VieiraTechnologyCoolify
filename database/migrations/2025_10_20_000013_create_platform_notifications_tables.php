<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications of the bell at the top of the dashboard: announcements of the owner (news,
 * promotions, updates, maintenance) and the events of each account (invoices, limits,
 * suspensions), with what each user already read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('kind', 30);
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->string('action_url', 500)->nullable();
            $table->string('action_label', 60)->nullable();
            // all, customers, operators, plan (with "plan") or team (with "team_id").
            $table->string('audience', 20)->default('all');
            $table->string('plan', 64)->nullable();
            $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['audience', 'published_at']);
            $table->index(['team_id', 'published_at']);
        });

        Schema::create('platform_notification_reads', function (Blueprint $table) {
            $table->foreignId('platform_notification_id')->constrained('platform_notifications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->primary(['platform_notification_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_notification_reads');
        Schema::dropIfExists('platform_notifications');
    }
};
