<?php

declare(strict_types=1);

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Training providers (tenants). Platform-level table: read by the resolver before a tenant is known.
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug', 63)->unique();
            $table->string('status', 20)->default('trial')->index(); // trial|active|suspended|archived
            $table->string('accreditation_number')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->jsonb('branding')->default('{}');
            $table->jsonb('settings')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        // Hosts a tenant is reachable on: its platform sub-domain and any verified custom domains.
        Schema::create('tenant_domains', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('host')->unique();
            $table->string('type', 16); // subdomain|custom
            $table->boolean('is_primary')->default(false);
            $table->string('verification_token', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'type']);
        });

        // Training centres / campuses. Tenant-owned: protected by row-level security.
        Schema::create('centres', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('geofence_radius_m')->default(150);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code']);
            $table->index('tenant_id');
        });

        Rls::enable('centres');
    }

    public function down(): void
    {
        Rls::disable('centres');
        Schema::dropIfExists('centres');
        Schema::dropIfExists('tenant_domains');
        Schema::dropIfExists('tenants');
    }
};
