<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LGPD (Lei nº 13.709/2018): the choices of each user (policy accepted, promotions), the proof of
 * every acceptance and consent (art. 8, § 2º), the requests of the data subjects (arts. 18 to 20),
 * the register of security incidents (art. 48 and Resolução CD/ANPD nº 15/2024) and the record of
 * each deleted account. Invoices outlive the account that paid them: tax law keeps them for five
 * years (art. 16, I), with the payer data copied into them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->boolean('marketing')->default(false);
            $table->timestamp('marketing_updated_at')->nullable();
            $table->unsignedInteger('policy_version')->default(0);
            $table->timestamp('policy_accepted_at')->nullable();
            $table->timestamp('adult_confirmed_at')->nullable();
            $table->timestamps();
            $table->index('marketing');
        });

        // No foreign key: the proof is kept after the account is deleted.
        Schema::create('privacy_consents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('email')->nullable();
            $table->string('purpose', 30);
            $table->boolean('granted');
            $table->unsignedInteger('policy_version')->nullable();
            $table->string('source', 30)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('email');
            $table->string('type', 30);
            $table->text('message')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->timestamp('due_at');
            $table->text('answer')->nullable();
            $table->unsignedBigInteger('answered_by')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('privacy_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->timestamp('known_at');
            $table->timestamp('occurred_at')->nullable();
            $table->text('description');
            $table->text('data_categories');
            $table->unsignedInteger('affected_count')->nullable();
            $table->boolean('relevant_risk')->default(false);
            $table->text('risks')->nullable();
            $table->text('security_measures')->nullable();
            $table->text('mitigation')->nullable();
            $table->text('recommendations')->nullable();
            $table->text('delay_reason')->nullable();
            $table->timestamp('anpd_communicated_at')->nullable();
            $table->string('anpd_protocol', 100)->nullable();
            $table->string('communicated_audience', 20)->nullable();
            $table->timestamp('titulares_communicated_at')->nullable();
            $table->unsignedInteger('titulares_communicated_count')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('privacy_deleted_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name')->nullable();
            $table->string('email');
            $table->string('requested_by', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->text('error')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('retain_until')->index();
            $table->timestamps();
        });

        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
        });
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->change();
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
            $table->text('payer')->nullable();
            $table->timestamp('retain_until')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropColumn(['payer', 'retain_until']);
        });
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
        });
        Schema::dropIfExists('privacy_deleted_accounts');
        Schema::dropIfExists('privacy_incidents');
        Schema::dropIfExists('privacy_requests');
        Schema::dropIfExists('privacy_consents');
        Schema::dropIfExists('privacy_preferences');
    }
};
