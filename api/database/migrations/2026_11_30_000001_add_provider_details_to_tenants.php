<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3.1: company, accreditation and billing details per training provider, plus the reason and time of
 * the last status change. Kept on `tenants` (one row per provider); HQ edits the legal and accreditation
 * fields, the provider edits its contact and billing details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('legal_name')->nullable();
            $table->string('registration_number', 40)->nullable();
            $table->jsonb('address')->default('{}');
            $table->date('accreditation_expires_at')->nullable();
            $table->string('billing_email')->nullable();
            $table->jsonb('payment_terms')->default('{"mode": "pay_first", "days": 0}');
            $table->string('status_reason', 500)->nullable();
            $table->timestamp('status_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['legal_name', 'registration_number', 'address', 'accreditation_expires_at', 'billing_email', 'payment_terms', 'status_reason', 'status_changed_at']);
        });
    }
};
