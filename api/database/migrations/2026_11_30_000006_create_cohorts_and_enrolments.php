<?php

declare(strict_types=1);

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3.6a: cohorts and enrolments (tenant, RLS).
 *
 * A cohort is pinned to the programme version it started on. An enrolment takes one seat from a licence pool.
 * The seat rules live in the database as well as in code: a trigger locks the pool row and refuses an
 * enrolment that would put the pool over its seats, so two admins enrolling at once can never oversell.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cohorts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('centre_id')->constrained('centres');
            $table->foreignUuid('programme_id')->constrained('programmes');
            $table->foreignUuid('programme_version_id')->constrained('programme_versions');
            $table->string('name', 120);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('qcto_type', 20)->default('qualification'); // qualification · skills_programme · none
            $table->timestamp('qcto_submitted_at')->nullable();
            $table->string('join_code', 12)->unique();
            $table->boolean('join_open')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'starts_on']);
        });
        DB::statement("ALTER TABLE cohorts ADD CONSTRAINT cohorts_qcto_type CHECK (qcto_type IN ('qualification', 'skills_programme', 'none'))");
        DB::statement('ALTER TABLE cohorts ADD CONSTRAINT cohorts_dates CHECK (ends_on IS NULL OR ends_on >= starts_on)');
        Rls::enable('cohorts');

        Schema::create('cohort_facilitators', function (Blueprint $table): void {
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cohort_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['cohort_id', 'user_id']);
        });
        Rls::enable('cohort_facilitators');

        Schema::create('enrolments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('learner_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cohort_id')->constrained();
            $table->foreignUuid('programme_id')->constrained('programmes');
            $table->foreignUuid('programme_version_id')->constrained('programme_versions');
            $table->foreignUuid('licence_pool_id')->constrained('licence_pools');
            $table->boolean('holds_seat')->default(true);
            $table->string('status', 12)->default('enrolled'); // enrolled · active · completed · withdrawn · no_show
            $table->unsignedTinyInteger('progress')->default(0); // percent, from learning activity (Sprint 4+)
            $table->timestamp('seat_taken_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('reason', 200)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'cohort_id']);
            $table->index(['licence_pool_id', 'holds_seat']);
        });
        DB::statement("ALTER TABLE enrolments ADD CONSTRAINT enrolments_status CHECK (status IN ('enrolled', 'active', 'completed', 'withdrawn', 'no_show'))");
        DB::statement('ALTER TABLE enrolments ADD CONSTRAINT enrolments_progress CHECK (progress BETWEEN 0 AND 100)');
        // One current enrolment per learner per programme.
        DB::statement("CREATE UNIQUE INDEX enrolments_one_current ON enrolments (tenant_id, learner_id, programme_id) WHERE status IN ('enrolled', 'active')");
        Rls::enable('enrolments');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enrolment_seat_check() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                pool_seats integer;
                pool_status text;
                taken integer;
            BEGIN
                IF NOT NEW.holds_seat THEN
                    RETURN NEW;
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.holds_seat AND OLD.licence_pool_id = NEW.licence_pool_id THEN
                    RETURN NEW; -- already counted in this pool
                END IF;
                SELECT seats, status INTO pool_seats, pool_status FROM licence_pools WHERE id = NEW.licence_pool_id FOR UPDATE;
                IF pool_status IS DISTINCT FROM 'active' THEN -- also refuses a pool this provider cannot see
                    RAISE EXCEPTION 'licence pool % is not active', NEW.licence_pool_id USING ERRCODE = 'check_violation';
                END IF;
                SELECT count(*) INTO taken FROM enrolments WHERE licence_pool_id = NEW.licence_pool_id AND holds_seat AND id <> NEW.id;
                IF taken + 1 > pool_seats THEN
                    RAISE EXCEPTION 'licence pool % has no free seats', NEW.licence_pool_id USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER enrolments_seat_check BEFORE INSERT OR UPDATE OF holds_seat, licence_pool_id ON enrolments
                FOR EACH ROW EXECUTE FUNCTION enrolment_seat_check();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS enrolments_seat_check ON enrolments; DROP FUNCTION IF EXISTS enrolment_seat_check();');
        foreach (['enrolments', 'cohort_facilitators', 'cohorts'] as $t) {
            Rls::disable($t);
            Schema::dropIfExists($t);
        }
    }
};
