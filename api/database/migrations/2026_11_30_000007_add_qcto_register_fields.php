<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3.6b: what QCTO's Learner Enrolment and Readiness for EISA file (columns A–AQ) needs.
 *
 * Equity, disability status and disability rating are special personal information (POPIA s26). QCTO requires
 * them for statutory reporting; they are stored encrypted and never written to logs or the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learners', function (Blueprint $table): void {
            $table->string('title', 6)->nullable();
            $table->string('middle_name', 120)->nullable();
            $table->string('alt_id_type', 3)->nullable(); // 527 passport · 565 refugee · 538 work permit · 540 birth certificate
            $table->text('equity_encrypted')->nullable();
            $table->text('disability_encrypted')->nullable(); // {status, rating}
            $table->string('nationality_code', 4)->nullable();
            $table->string('citizen_status', 2)->nullable();
            $table->string('immigrant_status', 2)->nullable();
            $table->string('socioeconomic_code', 2)->nullable();
            $table->jsonb('home_address')->nullable(); // {line1, line2, line3, postal_code}
            $table->jsonb('postal_address')->nullable(); // null = same as home
            $table->string('province_code', 1)->nullable();
            $table->string('stats_area_code', 20)->nullable();
            $table->timestamp('popi_agreed_at')->nullable();
            $table->string('flc_code', 2)->nullable();
            $table->string('flc_sor_number', 40)->nullable();
        });
        Schema::table('cohorts', function (Blueprint $table): void {
            $table->string('qcto_qualification_id', 20)->nullable();
            $table->string('readiness_type', 1)->default('4'); // 4 = SDP training and assessment for readiness to EISA
            $table->unsignedTinyInteger('qcto_reminder_level')->default(0);
        });
        Schema::table('enrolments', function (Blueprint $table): void {
            $table->date('sor_issued_on')->nullable(); // statement of results
        });
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('qcto_sdp_code', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('qcto_sdp_code'));
        Schema::table('enrolments', fn (Blueprint $t) => $t->dropColumn('sor_issued_on'));
        Schema::table('cohorts', fn (Blueprint $t) => $t->dropColumn(['qcto_qualification_id', 'readiness_type', 'qcto_reminder_level']));
        Schema::table('learners', fn (Blueprint $t) => $t->dropColumn(['title', 'middle_name', 'alt_id_type', 'equity_encrypted', 'disability_encrypted', 'nationality_code', 'citizen_status', 'immigrant_status', 'socioeconomic_code', 'home_address', 'postal_address', 'province_code', 'stats_area_code', 'popi_agreed_at', 'flc_code', 'flc_sor_number']));
    }
};
