<?php

declare(strict_types=1);

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 5.2: the assessor's decision on a practical task (a real-job simulator run or an observation):
 * competent or not yet, with feedback linked to the lesson or step, the evidence hash, and the declaration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('enrolment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('assessment_task_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('round');                 // 1st, 2nd, 3rd opportunity
            $table->string('result', 10);                          // competent · not_yet
            $table->foreignUuid('attempt_id')->nullable()->constrained()->nullOnDelete(); // the simulator run judged
            $table->jsonb('observation')->nullable();              // [{item, met, note}]
            $table->text('feedback')->nullable();
            $table->jsonb('links')->default('[]');                 // [{type: step|lesson, ref, label}]
            $table->string('evidence_hash', 64)->nullable();       // sha256 of the evidence as judged
            $table->foreignUuid('assessor_id')->constrained('users');
            $table->timestampTz('declared_at');                    // "this is the learner's own work, judged fairly"
            $table->timestampsTz();
            $table->index(['tenant_id', 'enrolment_id', 'assessment_task_id']);
            $table->index(['tenant_id', 'created_at']);
        });
        Rls::enable('assessment_decisions');
    }

    public function down(): void
    {
        Rls::disable('assessment_decisions');
        Schema::dropIfExists('assessment_decisions');
    }
};
