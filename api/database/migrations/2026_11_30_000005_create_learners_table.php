<?php

declare(strict_types=1);

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3.5: learners (tenant, RLS).
 *
 * A learner record exists from the moment the provider adds them (so they can be enrolled before they accept
 * the invitation); it is linked to a user account when the invitation is accepted.
 * ID numbers (POPIA): stored encrypted (app key, outside the database), with a keyed hash for finding duplicates
 * without decrypting, and the last 4 digits for masked display. The full number is never logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learners', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('first_names', 120);
            $table->string('surname', 120);
            $table->string('phone', 20)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('id_type', 10); // sa_id · passport
            $table->text('id_number_encrypted');
            $table->char('id_number_hash', 64);
            $table->string('id_last4', 4);
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable(); // female · male (from the SA ID number)
            $table->string('citizenship', 20)->nullable(); // sa · permanent_resident · foreign
            $table->string('home_language', 10)->nullable();
            $table->string('qcto_learner_number', 40)->nullable();
            $table->string('status', 10)->default('invited'); // invited · active · inactive
            $table->uuid('created_by')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id_number_hash']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'surname']);
        });
        DB::statement("ALTER TABLE learners ADD CONSTRAINT learners_status CHECK (status IN ('invited', 'active', 'inactive'))");
        DB::statement("ALTER TABLE learners ADD CONSTRAINT learners_id_type CHECK (id_type IN ('sa_id', 'passport'))");
        DB::statement('CREATE UNIQUE INDEX learners_phone_unique ON learners (tenant_id, phone) WHERE phone IS NOT NULL');
        Rls::enable('learners');

        Schema::table('invitations', function (Blueprint $table): void {
            $table->foreignUuid('learner_id')->nullable()->constrained('learners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('learner_id');
        });
        Rls::disable('learners');
        Schema::dropIfExists('learners');
    }
};
