<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_regions', function (Blueprint $table) {
            $table->string('logs_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_regions', function (Blueprint $table) {
            $table->dropColumn('logs_url');
        });
    }
};
