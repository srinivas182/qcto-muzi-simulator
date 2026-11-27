<?php

declare(strict_types=1);

use App\Authorization\RoleRegistry;
use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // System roles (tenant_id NULL, permissions in config/roles.php) and custom roles.
        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->string('scope', 16); // platform|tenant|external
            $table->boolean('is_system')->default(false);
            $table->jsonb('permissions')->default('[]'); // custom roles only
            $table->timestamps();
            $table->index('tenant_id');
        });
        DB::statement('CREATE UNIQUE INDEX roles_shared_key_unique ON roles (key) WHERE tenant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX roles_tenant_key_unique ON roles (tenant_id, key) WHERE tenant_id IS NOT NULL');

        Schema::create('role_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('centre_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id']);
        });
        DB::statement('CREATE UNIQUE INDEX role_user_unique ON role_user (user_id, role_id, coalesce(centre_id, \'00000000-0000-0000-0000-000000000000\'::uuid))');

        // Append-only audit log. No foreign keys: history must outlive the records it describes.
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('actor_id')->nullable()->index();
            $table->string('actor_name')->nullable();
            $table->uuid('support_session_id')->nullable();
            $table->string('action', 80)->index();
            $table->string('subject_type', 80)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->jsonb('changes')->nullable();
            $table->jsonb('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER audit_logs_no_update_or_delete
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_immutable();
        SQL);

        // HQ read-only support views of a provider. Visible to that provider.
        Schema::create('support_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('staff_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('staff_name');
            $table->string('reason', 500);
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_by', 16)->nullable(); // staff|provider|expiry
            $table->timestamps();
        });

        Rls::enableWithSharedRows('roles');
        Rls::enable('role_user');
        Rls::enable('audit_logs');
        Rls::enable('support_sessions');

        // During an HQ support session the staff member's own user row must be readable in the
        // provider's context (and nothing else of HQ). Replace the users policy accordingly.
        DB::statement('DROP POLICY tenant_isolation ON users');
        $own = "(tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid OR current_setting('app.platform_access', true) = 'on')";
        $support = "(id = NULLIF(current_setting('app.support_user_id', true), '')::uuid)";
        DB::statement("CREATE POLICY tenant_isolation ON users USING ({$own} OR {$support}) WITH CHECK {$own}");

        RoleRegistry::syncSystemRoles();
    }

    public function down(): void
    {
        DB::statement('DROP POLICY tenant_isolation ON users');
        $own = "(tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid OR current_setting('app.platform_access', true) = 'on')";
        DB::statement("CREATE POLICY tenant_isolation ON users USING {$own} WITH CHECK {$own}");

        foreach (['support_sessions', 'audit_logs', 'role_user', 'roles'] as $table) {
            Rls::disable($table);
        }
        Schema::dropIfExists('support_sessions');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update_or_delete ON audit_logs; DROP FUNCTION IF EXISTS audit_logs_immutable();');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
    }
};
