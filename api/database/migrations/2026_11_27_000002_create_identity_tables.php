<?php

declare(strict_types=1);

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // People. tenant_id NULL = platform (HQ) staff. Learners usually sign in with a mobile number.
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable(); // E.164, e.g. +27821234567
            $table->string('password')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('locale', 5)->default('en');
            $table->string('status', 16)->default('active'); // active|disabled
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('tenant_id');
        });
        // Unique per tenant; platform staff (tenant_id NULL) unique among themselves.
        DB::statement('CREATE UNIQUE INDEX users_tenant_email_unique ON users (tenant_id, lower(email)) WHERE email IS NOT NULL AND tenant_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX users_tenant_phone_unique ON users (tenant_id, phone) WHERE phone IS NOT NULL AND tenant_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX users_platform_email_unique ON users (lower(email)) WHERE email IS NOT NULL AND tenant_id IS NULL');

        // Invitations: join by link or QR code.
        Schema::create('invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('role', 40);
            $table->string('token_hash', 64)->unique();
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index('tenant_id');
        });

        // POPIA: what each person agreed to, which version, when and from where.
        Schema::create('user_consents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 40);
            $table->string('version', 20);
            $table->boolean('granted');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'purpose']);
        });

        Schema::create('password_resets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        foreach (['users', 'invitations', 'user_consents', 'password_resets'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        foreach (['password_resets', 'user_consents', 'invitations', 'users'] as $table) {
            Rls::disable($table);
            Schema::dropIfExists($table);
        }
    }
};
