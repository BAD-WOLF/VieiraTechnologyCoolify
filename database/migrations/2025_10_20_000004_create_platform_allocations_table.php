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
        Schema::create('platform_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('resource_type');
            $table->unsignedBigInteger('resource_id');
            $table->unsignedBigInteger('server_id');
            $table->unsignedInteger('pull_request_id')->default(0);
            $table->double('cpus')->default(0);
            $table->unsignedInteger('memory_mb')->default(0);
            $table->unsignedInteger('containers')->default(0);
            $table->timestamps();

            $table->unique(['resource_type', 'resource_id', 'server_id', 'pull_request_id'], 'platform_allocations_resource_unique');
            $table->index('team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_allocations');
    }
};
