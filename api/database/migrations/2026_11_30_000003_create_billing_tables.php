<?php

declare(strict_types=1);

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3.3: billing.
 *
 *  - billing_settings (platform, one row): the seller printed on invoices, EFT details, VAT rate, prefixes.
 *  - document_counters (platform): gap-free invoice and credit note numbers, taken under a row lock.
 *  - licence_pools, orders, invoices, credit_notes, payments (tenant, RLS).
 * Invoices are never edited or deleted (trigger): only their status, payments and reminders move. Mistakes are
 * corrected with credit notes. All money is integer cents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 20)->unique()->default('default');
            $table->string('seller_name')->nullable();
            $table->string('registration_number', 40)->nullable();
            $table->string('vat_number', 20)->nullable();
            $table->jsonb('address')->default('{}');
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->jsonb('bank')->default('{}');
            $table->decimal('vat_rate', 5, 2)->default(15);
            $table->string('invoice_prefix', 12)->default('INV-');
            $table->string('credit_note_prefix', 12)->default('CN-');
            $table->unsignedSmallInteger('due_days')->default(7);
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('document_counters', function (Blueprint $table): void {
            $table->string('name', 20)->primary();
            $table->unsignedBigInteger('next_number')->default(1);
        });
        DB::table('document_counters')->insert([['name' => 'invoice', 'next_number' => 1], ['name' => 'credit_note', 'next_number' => 1]]);

        Schema::create('licence_pools', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('package_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('programme_id')->constrained()->restrictOnDelete();
            $table->string('billing', 10);
            $table->unsignedSmallInteger('term_months')->nullable();
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedInteger('seats')->default(0);
            $table->unsignedInteger('seats_next_month')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->string('status', 12)->default('pending'); // pending · active · expired · cancelled
            $table->jsonb('renewal_reminders')->default('[]');
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE licence_pools ADD CONSTRAINT licence_pools_status CHECK (status IN ('pending', 'active', 'expired', 'cancelled'))");

        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('package_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('licence_pool_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // new · top_up
            $table->unsignedInteger('seats');
            $table->unsignedBigInteger('unit_price_cents');
            $table->jsonb('pricing');
            $table->string('status', 12)->default('invoiced'); // invoiced · paid · cancelled
            $table->uuid('ordered_by')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30)->unique();
            $table->string('kind', 10); // order · monthly
            $table->foreignUuid('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('licence_pool_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            $table->jsonb('seller');
            $table->jsonb('buyer');
            $table->jsonb('lines');
            $table->unsignedBigInteger('subtotal_cents');
            $table->decimal('vat_rate', 5, 2);
            $table->unsignedBigInteger('vat_cents');
            $table->unsignedBigInteger('total_cents');
            $table->unsignedBigInteger('paid_cents')->default(0);
            $table->string('status', 10)->default('issued'); // issued · paid · credited
            $table->timestamp('paid_at')->nullable();
            $table->unsignedSmallInteger('reminder_level')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'due_date']);
            $table->unique(['licence_pool_id', 'period_start']);
        });

        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained()->restrictOnDelete();
            $table->string('number', 30)->unique();
            $table->date('issue_date');
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('vat_cents');
            $table->unsignedBigInteger('total_cents');
            $table->string('reason', 500);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained()->restrictOnDelete();
            $table->string('method', 10); // eft · gateway
            $table->string('gateway', 20)->nullable();
            $table->string('gateway_reference', 120)->nullable();
            $table->unsignedBigInteger('amount_cents');
            $table->string('reference', 120)->nullable();
            $table->date('paid_on');
            $table->uuid('recorded_by')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'gateway_reference']);
        });

        foreach (['licence_pools', 'orders', 'invoices', 'credit_notes', 'payments'] as $table) {
            Rls::enable($table);
        }

        // Invoices and credit notes are legal documents: never deleted; an invoice's content never changes.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION billing_document_locked() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION '% cannot be deleted', TG_TABLE_NAME;
                END IF;
                IF TG_TABLE_NAME = 'credit_notes' OR (to_jsonb(NEW) - 'status' - 'paid_cents' - 'paid_at' - 'reminder_level' - 'updated_at')
                        <> (to_jsonb(OLD) - 'status' - 'paid_cents' - 'paid_at' - 'reminder_level' - 'updated_at') THEN
                    RAISE EXCEPTION '% % cannot be changed; issue a credit note instead', TG_TABLE_NAME, OLD.number;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
        DB::statement('CREATE TRIGGER invoices_locked BEFORE UPDATE OR DELETE ON invoices FOR EACH ROW EXECUTE FUNCTION billing_document_locked()');
        DB::statement('CREATE TRIGGER credit_notes_locked BEFORE UPDATE OR DELETE ON credit_notes FOR EACH ROW EXECUTE FUNCTION billing_document_locked()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS credit_notes_locked ON credit_notes');
        DB::statement('DROP TRIGGER IF EXISTS invoices_locked ON invoices');
        DB::statement('DROP FUNCTION IF EXISTS billing_document_locked()');
        foreach (['payments', 'credit_notes', 'invoices', 'orders', 'licence_pools'] as $table) {
            Rls::disable($table);
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('document_counters');
        Schema::dropIfExists('billing_settings');
    }
};
