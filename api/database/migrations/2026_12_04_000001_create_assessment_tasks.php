<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 5.1a: the assessment plan of a programme version (HQ content, shared by all providers).
 * Each summative task belongs to one QCTO component and covers outcomes:
 *   knowledge  · test         a timed test drawn from a question bank (content.questions)
 *   practical  · simulator    a real-job simulator run (activity_id) reviewed by the assessor
 *   practical  · observation  a short checklist the assessor ticks on the phone (settings.items)
 *   workplace  · logbook      confirmed workplace days and tasks (settings.min_days)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('programme_version_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('title', 200);
            $table->string('component', 10);
            $table->string('kind', 12);
            $table->foreignUuid('activity_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->boolean('required')->default(true);
            $table->jsonb('settings')->default('{}');
            $table->jsonb('content')->default('{}');   // tests: questions, i18n, review
            $table->jsonb('draft')->nullable();
            $table->timestamp('draft_updated_at')->nullable();
            $table->string('status', 10)->default('draft');
            $table->unsignedInteger('content_version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['programme_version_id', 'code']);
        });
        DB::statement("ALTER TABLE assessment_tasks ADD CONSTRAINT assessment_tasks_component CHECK (component IN ('knowledge', 'practical', 'workplace'))");
        DB::statement("ALTER TABLE assessment_tasks ADD CONSTRAINT assessment_tasks_kind CHECK (kind IN ('test', 'simulator', 'observation', 'logbook'))");
        DB::statement("ALTER TABLE assessment_tasks ADD CONSTRAINT assessment_tasks_status CHECK (status IN ('draft', 'published', 'retired'))");

        Schema::create('assessment_task_outcomes', function (Blueprint $table): void {
            $table->foreignUuid('assessment_task_id')->constrained()->cascadeOnDelete();
            $table->string('outcome_code', 20);
            $table->primary(['assessment_task_id', 'outcome_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_task_outcomes');
        Schema::dropIfExists('assessment_tasks');
    }
};
