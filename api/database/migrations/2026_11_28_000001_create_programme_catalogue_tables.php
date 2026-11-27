<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-owned programme catalogue (no tenant_id): programmes → versions → duty areas → outcomes and
 * evidence requirements, plus qualification references and the guide's reference pages.
 *
 * Published and retired versions are frozen by a database trigger: their content (and their children)
 * cannot be changed or deleted; a published version may only move to "retired". Cohorts therefore always
 * keep exactly the content they enrolled under.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programmes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('prefix', 10)->unique();
            $table->string('title', 150);
            $table->string('family', 120);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('programme_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('programme_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('status', 20)->default('draft'); // draft · in_review · published · retired
            $table->text('summary');
            $table->text('purpose');
            $table->jsonb('occupations')->default('[]');
            $table->jsonb('workplace_requirements')->default('[]');
            $table->jsonb('tools')->default('[]');
            $table->jsonb('to_confirm')->default('[]');
            $table->jsonb('source')->nullable();
            $table->text('change_notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['programme_id', 'number']);
        });
        DB::statement("CREATE UNIQUE INDEX programme_versions_one_published ON programme_versions (programme_id) WHERE status = 'published'");
        DB::statement("CREATE UNIQUE INDEX programme_versions_one_open_draft ON programme_versions (programme_id) WHERE status IN ('draft', 'in_review')");
        DB::statement("ALTER TABLE programme_versions ADD CONSTRAINT programme_versions_status CHECK (status IN ('draft', 'in_review', 'published', 'retired'))");

        Schema::create('qualification_refs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('programme_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 30); // occupational · legacy · skills_programme · related · note
            $table->string('saqa_id', 10)->nullable()->index();
            $table->string('title', 255)->nullable();
            $table->unsignedSmallInteger('nqf_level')->nullable();
            $table->unsignedSmallInteger('credits')->nullable();
            $table->text('source_text');
            $table->timestamps();
        });

        Schema::create('duty_areas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('programme_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('title', 200);
            $table->timestamps();
            $table->unique(['programme_version_id', 'number']);
        });

        Schema::create('outcomes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('programme_version_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('duty_area_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->unsignedSmallInteger('position');
            $table->text('statement');
            $table->string('plain_words', 255)->nullable();
            $table->timestamps();
            $table->unique(['programme_version_id', 'code']);
        });

        Schema::create('evidence_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('programme_version_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('duty_area_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('description');
            $table->string('type', 20); // document · photo · observation · sign_off · feedback · record
            $table->unsignedSmallInteger('min_count')->default(1);
            $table->timestamps();
        });

        Schema::create('reference_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('title', 200);
            $table->text('markdown');
            $table->jsonb('source')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION programme_content_locked() RETURNS trigger AS $$
            DECLARE
                version_status text;
            BEGIN
                IF TG_TABLE_NAME = 'programme_versions' THEN
                    IF TG_OP = 'DELETE' AND OLD.status IN ('published', 'retired') THEN
                        RAISE EXCEPTION 'programme version % is frozen (status %)', OLD.number, OLD.status;
                    END IF;
                    IF TG_OP = 'UPDATE' AND OLD.status IN ('published', 'retired') THEN
                        IF NOT (OLD.status = 'published' AND NEW.status = 'retired'
                                AND (to_jsonb(NEW) - 'status' - 'retired_at' - 'updated_at') = (to_jsonb(OLD) - 'status' - 'retired_at' - 'updated_at')) THEN
                            RAISE EXCEPTION 'programme version % is frozen (status %)', OLD.number, OLD.status;
                        END IF;
                    END IF;
                    RETURN COALESCE(NEW, OLD);
                END IF;

                SELECT status INTO version_status FROM programme_versions
                    WHERE id = COALESCE(NEW.programme_version_id, OLD.programme_version_id);
                IF version_status IN ('published', 'retired') THEN
                    RAISE EXCEPTION 'programme version is frozen (status %): % cannot change', version_status, TG_TABLE_NAME;
                END IF;
                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        DB::statement('CREATE TRIGGER programme_versions_locked BEFORE UPDATE OR DELETE ON programme_versions FOR EACH ROW EXECUTE FUNCTION programme_content_locked()');
        foreach (['qualification_refs', 'duty_areas', 'outcomes', 'evidence_requirements'] as $child) {
            DB::statement("CREATE TRIGGER {$child}_locked BEFORE INSERT OR UPDATE OR DELETE ON {$child} FOR EACH ROW EXECUTE FUNCTION programme_content_locked()");
        }
    }

    public function down(): void
    {
        foreach (['evidence_requirements', 'outcomes', 'duty_areas', 'qualification_refs'] as $child) {
            DB::statement("DROP TRIGGER IF EXISTS {$child}_locked ON {$child}");
        }
        DB::statement('DROP TRIGGER IF EXISTS programme_versions_locked ON programme_versions');
        Schema::dropIfExists('reference_pages');
        Schema::dropIfExists('evidence_requirements');
        Schema::dropIfExists('outcomes');
        Schema::dropIfExists('duty_areas');
        Schema::dropIfExists('qualification_refs');
        Schema::dropIfExists('programme_versions');
        Schema::dropIfExists('programmes');
        DB::statement('DROP FUNCTION IF EXISTS programme_content_locked()');
    }
};
