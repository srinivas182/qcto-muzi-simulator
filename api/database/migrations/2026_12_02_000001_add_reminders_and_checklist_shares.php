<?php

declare(strict_types=1);

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4.6b: gentle reminders for quiet learners, and read-only checklist links a learner can share.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learners', function (Blueprint $table) {
            $table->timestampTz('reminders_off_at')->nullable(); // the learner turned reminders off
        });
        Schema::table('enrolments', function (Blueprint $table) {
            $table->timestampTz('reminded_at')->nullable();
            $table->unsignedSmallInteger('reminders_sent')->default(0); // reset when the learner is active again
        });

        Schema::create('checklist_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('enrolment_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique(); // sha256 of the token; the token itself is never stored
            $table->string('label', 80)->nullable();    // "For my mentor at Acme"
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->timestampTz('last_viewed_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'enrolment_id']);
        });
        Rls::enable('checklist_shares');
    }

    public function down(): void
    {
        Rls::disable('checklist_shares');
        Schema::dropIfExists('checklist_shares');
        Schema::table('enrolments', fn (Blueprint $table) => $table->dropColumn(['reminded_at', 'reminders_sent']));
        Schema::table('learners', fn (Blueprint $table) => $table->dropColumn('reminders_off_at'));
    }
};
