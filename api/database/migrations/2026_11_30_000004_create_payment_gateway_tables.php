<?php

declare(strict_types=1);

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3.4: online payments.
 *
 *  - payment_gateways (platform): one row per gateway Super Admin has configured; keys encrypted with the app key.
 *  - payment_attempts (tenant, RLS): one "Pay online" click; its id is the reference sent to the gateway.
 *  - payment_events (platform): every gateway notification, stored once per gateway and event id, so a repeated
 *    notification is never processed twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('driver', 20)->unique();
            $table->boolean('enabled')->default(false);
            $table->string('mode', 4)->default('test'); // test · live
            $table->text('credentials')->nullable(); // encrypted JSON
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message', 300)->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained()->restrictOnDelete();
            $table->string('gateway', 20);
            $table->string('reference', 40)->unique();
            $table->string('gateway_reference', 120)->nullable()->index();
            $table->unsignedBigInteger('amount_cents');
            $table->string('status', 10)->default('started'); // started · paid · failed
            $table->string('outcome', 200)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });
        Rls::enable('payment_attempts');

        Schema::create('payment_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('gateway', 20);
            $table->string('event_id', 160);
            $table->uuid('payment_attempt_id')->nullable()->index();
            $table->jsonb('payload');
            $table->string('outcome', 200)->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Rls::disable('payment_attempts');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payment_gateways');
    }
};
