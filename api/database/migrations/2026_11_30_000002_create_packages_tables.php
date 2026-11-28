<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3.2: packages and the price list (platform data).
 *
 * A package sells seats on one programme: simulators, courses or both, billed either for a fixed term
 * (price per seat for N months, invoiced once) or monthly (price per seat per month). Prices are bands by
 * number of seats, in cents excluding VAT. Published and retired packages are frozen by a trigger, so a
 * provider's purchase always refers to exactly what was on sale; the only allowed change is published → retired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->foreignUuid('programme_id')->constrained()->restrictOnDelete();
            $table->boolean('includes_simulators')->default(true);
            $table->boolean('includes_courses')->default(true);
            $table->string('billing', 10); // term · monthly
            $table->unsignedSmallInteger('term_months')->nullable();
            $table->unsignedInteger('min_seats')->default(1);
            $table->string('status', 12)->default('draft'); // draft · published · retired
            $table->uuid('created_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'programme_id']);
        });
        DB::statement("ALTER TABLE packages ADD CONSTRAINT packages_billing CHECK (billing IN ('term', 'monthly'))");
        DB::statement("ALTER TABLE packages ADD CONSTRAINT packages_status CHECK (status IN ('draft', 'published', 'retired'))");
        DB::statement("ALTER TABLE packages ADD CONSTRAINT packages_term CHECK ((billing = 'term' AND term_months BETWEEN 1 AND 60) OR (billing = 'monthly' AND term_months IS NULL))");
        DB::statement('ALTER TABLE packages ADD CONSTRAINT packages_content CHECK (includes_simulators OR includes_courses)');

        Schema::create('package_prices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('package_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('from_seats');
            $table->unsignedBigInteger('unit_price_cents');
            $table->unique(['package_id', 'from_seats']);
        });
        DB::statement('ALTER TABLE package_prices ADD CONSTRAINT package_prices_positive CHECK (from_seats >= 1 AND unit_price_cents > 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION package_locked() RETURNS trigger AS $$
            DECLARE
                pkg_status text;
            BEGIN
                IF TG_TABLE_NAME = 'packages' THEN
                    IF TG_OP = 'DELETE' AND OLD.status <> 'draft' THEN
                        RAISE EXCEPTION 'package % is frozen (status %)', OLD.code, OLD.status;
                    END IF;
                    IF TG_OP = 'UPDATE' AND OLD.status <> 'draft' THEN
                        IF NOT (OLD.status = 'published' AND NEW.status = 'retired'
                                AND (to_jsonb(NEW) - 'status' - 'retired_at' - 'updated_at') = (to_jsonb(OLD) - 'status' - 'retired_at' - 'updated_at')) THEN
                            RAISE EXCEPTION 'package % is frozen (status %)', OLD.code, OLD.status;
                        END IF;
                    END IF;
                    RETURN COALESCE(NEW, OLD);
                END IF;

                SELECT status INTO pkg_status FROM packages WHERE id = COALESCE(NEW.package_id, OLD.package_id);
                IF pkg_status IS NOT NULL AND pkg_status <> 'draft' THEN
                    RAISE EXCEPTION 'package is frozen (status %): prices cannot change', pkg_status;
                END IF;
                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;
            SQL);
        DB::statement('CREATE TRIGGER packages_locked BEFORE UPDATE OR DELETE ON packages FOR EACH ROW EXECUTE FUNCTION package_locked()');
        DB::statement('CREATE TRIGGER package_prices_locked BEFORE INSERT OR UPDATE OR DELETE ON package_prices FOR EACH ROW EXECUTE FUNCTION package_locked()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS package_prices_locked ON package_prices');
        DB::statement('DROP TRIGGER IF EXISTS packages_locked ON packages');
        Schema::dropIfExists('package_prices');
        Schema::dropIfExists('packages');
        DB::statement('DROP FUNCTION IF EXISTS package_locked()');
    }
};
