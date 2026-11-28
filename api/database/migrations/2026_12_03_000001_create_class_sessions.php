<?php

declare(strict_types=1);

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sprint 4.7b: class mode — a facilitator runs one scenario with the whole class and watches it live. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cohort_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('activity_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 10); // practice | job
            $table->foreignUuid('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'cohort_id', 'ended_at']);
        });
        Rls::enable('class_sessions');
    }

    public function down(): void
    {
        Rls::disable('class_sessions');
        Schema::dropIfExists('class_sessions');
    }
};
