<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('url', 2048);
            $table->text('secret');
            $table->json('events');
            $table->string('description')->nullable();
            $table->boolean('all_teams')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->string('disabled_reason')->nullable();
            $table->unsignedSmallInteger('last_response_status')->nullable();
            $table->timestamp('last_delivery_at')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('platform_webhook_id')->constrained('platform_webhooks')->cascadeOnDelete();
            $table->string('event_id', 40);
            $table->string('event_type', 64);
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('response_excerpt', 500)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['platform_webhook_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_webhook_deliveries');
        Schema::dropIfExists('platform_webhooks');
    }
};
