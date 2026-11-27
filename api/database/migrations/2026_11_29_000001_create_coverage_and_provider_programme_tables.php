<?php

declare(strict_types=1);

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 2.3.
 *
 *  - outcome_coverage (platform): which simulator or course covers each workplace outcome. Keyed by the
 *    stable outcome code (TS-1.3), so coverage carries over when HQ publishes a new version.
 *  - provider_programme_checks (tenant, RLS): a provider's answers to the programme's "to confirm before
 *    use" items. An item is identified by a hash of its wording: if HQ rewords it, it must be confirmed again.
 *  - provider_outcomes / provider_evidence_requirements (tenant, RLS): a provider's own additions on top of
 *    HQ's content, allowed only while HQ has switched them on for that provider. Soft-deleted so a provider
 *    code (TS-1.P1) is never reused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outcome_coverage', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('programme_id')->constrained()->cascadeOnDelete();
            $table->string('outcome_code', 20);
            $table->string('channel', 12); // simulator · course
            $table->string('status', 16); // planned · in_production · linked
            $table->string('title', 200)->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['programme_id', 'outcome_code', 'channel']);
        });
        DB::statement("ALTER TABLE outcome_coverage ADD CONSTRAINT outcome_coverage_channel CHECK (channel IN ('simulator', 'course'))");
        DB::statement("ALTER TABLE outcome_coverage ADD CONSTRAINT outcome_coverage_status CHECK (status IN ('planned', 'in_production', 'linked'))");

        Schema::create('provider_programme_checks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('programme_id')->constrained()->cascadeOnDelete();
            $table->char('item_key', 40);
            $table->text('item_text');
            $table->string('status', 16); // confirmed · not_applicable
            $table->string('note', 1000)->nullable();
            $table->unsignedInteger('version_number');
            $table->uuid('answered_by')->nullable();
            $table->timestamp('answered_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'programme_id', 'item_key']);
        });
        DB::statement("ALTER TABLE provider_programme_checks ADD CONSTRAINT provider_programme_checks_status CHECK (status IN ('confirmed', 'not_applicable'))");

        Schema::create('provider_outcomes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('programme_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('duty_area_number');
            $table->string('code', 24);
            $table->text('statement');
            $table->string('plain_words', 300)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'programme_id', 'code']);
        });

        Schema::create('provider_evidence_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('programme_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('duty_area_number');
            $table->text('description');
            $table->string('type', 20);
            $table->unsignedSmallInteger('min_count')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'programme_id']);
        });

        foreach (['provider_programme_checks', 'provider_outcomes', 'provider_evidence_requirements'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        foreach (['provider_evidence_requirements', 'provider_outcomes', 'provider_programme_checks'] as $table) {
            Rls::disable($table);
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('outcome_coverage');
    }
};
