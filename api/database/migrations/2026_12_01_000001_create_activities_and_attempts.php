<?php

declare(strict_types=1);

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4.1: learning content and progress.
 *
 * activities (HQ content, platform-wide): lessons and simulator scenarios of a programme version, linked to
 * outcome codes. attempts (per provider, RLS): what a learner did. Each attempt has an ID made on the device,
 * unique per provider, so syncing the same attempt twice changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('programme_version_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // lesson · scenario
            $table->string('code', 30);
            $table->string('title', 200);
            $table->string('summary', 500)->nullable();
            $table->unsignedSmallInteger('position');
            $table->boolean('required')->default(true);
            $table->unsignedTinyInteger('weight')->default(1);
            $table->unsignedSmallInteger('minutes')->default(5);
            $table->string('status', 10)->default('draft'); // draft · published · retired
            $table->unsignedInteger('content_version')->default(1);
            $table->jsonb('content')->default('{}'); // lesson blocks or scenario definition (4.2, 4.4)
            $table->timestamp('published_at')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['programme_version_id', 'code']);
            $table->index(['programme_version_id', 'status', 'position']);
        });
        DB::statement("ALTER TABLE activities ADD CONSTRAINT activities_kind CHECK (kind IN ('lesson', 'scenario'))");
        DB::statement("ALTER TABLE activities ADD CONSTRAINT activities_status CHECK (status IN ('draft', 'published', 'retired'))");
        DB::statement('ALTER TABLE activities ADD CONSTRAINT activities_weight CHECK (weight BETWEEN 1 AND 10)');

        Schema::create('activity_outcomes', function (Blueprint $table): void {
            $table->foreignUuid('activity_id')->constrained()->cascadeOnDelete();
            $table->string('outcome_code', 20);
            $table->primary(['activity_id', 'outcome_code']);
        });

        Schema::create('attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_id'); // made on the device
            $table->foreignUuid('enrolment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('activity_id')->constrained();
            $table->string('mode', 8); // lesson · practice · job
            $table->unsignedInteger('content_version');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->boolean('completed')->default(false);
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->jsonb('result')->default('{}'); // per step or block: answers, mistakes, hints used
            $table->string('device', 40)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'client_id']);
            $table->index(['enrolment_id', 'activity_id']);
        });
        DB::statement("ALTER TABLE attempts ADD CONSTRAINT attempts_mode CHECK (mode IN ('lesson', 'practice', 'job'))");
        DB::statement('ALTER TABLE attempts ADD CONSTRAINT attempts_score CHECK (score IS NULL OR score BETWEEN 0 AND 100)');
        Rls::enable('attempts');

        Schema::table('enrolments', function (Blueprint $table): void {
            $table->timestamp('last_activity_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('enrolments', fn (Blueprint $t) => $t->dropColumn('last_activity_at'));
        Rls::disable('attempts');
        Schema::dropIfExists('attempts');
        Schema::dropIfExists('activity_outcomes');
        Schema::dropIfExists('activities');
    }
};
