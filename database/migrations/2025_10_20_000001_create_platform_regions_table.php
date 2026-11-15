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
        Schema::create('platform_regions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->foreignId('platform_region_id')->nullable()->after('team_id')->constrained('platform_regions')->nullOnDelete();
            $table->unsignedBigInteger('platform_dedicated_team_id')->nullable()->after('platform_region_id')->index();
        });

        Schema::table('standalone_dockers', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->after('server_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('standalone_dockers', function (Blueprint $table) {
            $table->dropColumn('team_id');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('platform_region_id');
            $table->dropColumn('platform_dedicated_team_id');
        });

        Schema::dropIfExists('platform_regions');
    }
};
