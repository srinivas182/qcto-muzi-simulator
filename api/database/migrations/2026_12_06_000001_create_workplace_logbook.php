<?php

declare(strict_types=1);

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 5.3: the workplace component. A learner's placement (employer and mentor) and a daily logbook of what
 * they did against the workplace outcomes, with an optional small photo, confirmed by the mentor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workplaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('enrolment_id')->constrained()->cascadeOnDelete();
            $table->string('employer', 120);
            $table->string('site', 160)->nullable();             // town or branch, not a street address
            $table->string('mentor_name', 80);
            $table->string('mentor_phone', 16);                  // E.164, for the WhatsApp/SMS link and code
            $table->foreignUuid('mentor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('confirm_by', 10)->default('link');  // link · account
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['tenant_id', 'enrolment_id']);
        });
        Rls::enable('workplaces');

        Schema::create('logbook_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('enrolment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workplace_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_id');
            $table->date('day');
            $table->decimal('hours', 4, 1);
            $table->jsonb('outcomes')->default('[]');            // workplace outcome codes worked on
            $table->string('note', 500)->nullable();
            $table->string('photo_path', 200)->nullable();
            $table->unsignedInteger('photo_bytes')->nullable();
            $table->string('status', 10)->default('submitted');  // submitted · confirmed · queried
            $table->string('mentor_note', 300)->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->string('confirmed_via', 10)->nullable();     // link · account
            $table->string('confirmed_name', 80)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'client_id']);
            $table->unique(['enrolment_id', 'day']);
            $table->index(['tenant_id', 'workplace_id', 'status']);
        });
        Rls::enable('logbook_entries');
    }

    public function down(): void
    {
        Rls::disable('logbook_entries');
        Schema::dropIfExists('logbook_entries');
        Rls::disable('workplaces');
        Schema::dropIfExists('workplaces');
    }
};
