<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 5.2b: assessment accommodations per enrolment (extra time, read-aloud), copied onto each test sitting,
 * and a client id on decisions so an observation signed off offline is sent once, however often it is retried.
 * No reason is stored for an accommodation: POPIA minimal data (the reason may be health information).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrolments', fn (Blueprint $table) => $table->jsonb('accommodations')->nullable());
        Schema::table('test_sittings', fn (Blueprint $table) => $table->jsonb('accommodations')->nullable());
        Schema::table('assessment_decisions', function (Blueprint $table) {
            $table->uuid('client_id')->nullable();
            $table->unique(['tenant_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::table('assessment_decisions', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'client_id']);
            $table->dropColumn('client_id');
        });
        Schema::table('test_sittings', fn (Blueprint $table) => $table->dropColumn('accommodations'));
        Schema::table('enrolments', fn (Blueprint $table) => $table->dropColumn('accommodations'));
    }
};
