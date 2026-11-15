<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_tenants', function (Blueprint $table) {
            $table->string('otlp_token_hash', 64)->nullable()->unique();
            $table->timestamp('otlp_token_created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_tenants', function (Blueprint $table) {
            $table->dropUnique(['otlp_token_hash']);
            $table->dropColumn(['otlp_token_hash', 'otlp_token_created_at']);
        });
    }
};
