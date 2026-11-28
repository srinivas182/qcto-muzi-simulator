<?php

declare(strict_types=1);

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4.7c: pilot measures. Data used per learner (offline pack downloads) and a 3-question learner rating
 * after a simulator: was it easy to use, did it feel like real work, do you feel more ready for the job (1–5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrolments', function (Blueprint $table) {
            $table->unsignedInteger('pack_downloads')->default(0);
            $table->unsignedBigInteger('pack_bytes')->default(0);
        });
        Schema::create('learner_ratings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('enrolment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('activity_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('easy');
            $table->unsignedTinyInteger('real');
            $table->unsignedTinyInteger('ready');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'enrolment_id', 'activity_id']);
        });
        Rls::enable('learner_ratings');
    }

    public function down(): void
    {
        Rls::disable('learner_ratings');
        Schema::dropIfExists('learner_ratings');
        Schema::table('enrolments', fn (Blueprint $table) => $table->dropColumn(['pack_downloads', 'pack_bytes']));
    }
};
