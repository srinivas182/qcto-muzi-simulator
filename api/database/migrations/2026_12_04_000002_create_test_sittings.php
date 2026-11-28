<?php

declare(strict_types=1);

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 5.1b: a learner's sitting of a knowledge test. The paper (which questions, in which order, with
 * which option order) is fixed when the sitting starts; the deadline is the server's, not the device's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_sittings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('enrolment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('assessment_task_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('content_version');
            $table->unsignedTinyInteger('attempt');
            $table->string('locale', 5)->default('en');
            $table->string('status', 10)->default('open'); // open · submitted (short answers to mark) · marked
            $table->timestampTz('started_at');
            $table->timestampTz('deadline_at');
            $table->timestampTz('submitted_at')->nullable();
            $table->boolean('late')->default(false);       // answers arrived after the deadline, within the grace period
            $table->jsonb('paper');                        // [{id, options: [original indexes in shown order]}]
            $table->jsonb('answers')->default('{}');       // {questionId: shown index | [shown indexes] | text}
            $table->jsonb('marks')->default('{}');         // {questionId: marks awarded}
            $table->unsignedSmallInteger('score')->nullable(); // percent, once marked
            $table->string('result', 10)->nullable();      // competent · not_yet
            $table->uuid('marked_by')->nullable();
            $table->timestampTz('marked_at')->nullable();
            $table->string('device', 40)->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'enrolment_id', 'assessment_task_id']);
        });
        Rls::enable('test_sittings');
    }

    public function down(): void
    {
        Rls::disable('test_sittings');
        Schema::dropIfExists('test_sittings');
    }
};
