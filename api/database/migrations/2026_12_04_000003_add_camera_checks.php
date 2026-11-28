<?php

declare(strict_types=1);

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 5.1c: optional camera check during tests. Small photos (not video), with the learner's consent, seen
 * only by assessors and moderators, deleted 90 days after the result is final.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_sittings', function (Blueprint $table) {
            $table->boolean('camera')->default(false);              // this sitting runs with the camera check
            $table->timestampTz('camera_consent_at')->nullable();
        });
        Schema::create('sitting_photos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('test_sitting_id')->constrained()->cascadeOnDelete();
            $table->string('path', 200);
            $table->unsignedInteger('bytes');
            $table->timestampTz('taken_at');
            $table->timestampTz('delete_after')->nullable(); // set to 90 days after the result is final
            $table->timestampsTz();
            $table->index(['tenant_id', 'test_sitting_id']);
        });
        Rls::enable('sitting_photos');
    }

    public function down(): void
    {
        Rls::disable('sitting_photos');
        Schema::dropIfExists('sitting_photos');
        Schema::table('test_sittings', fn (Blueprint $table) => $table->dropColumn(['camera', 'camera_consent_at']));
    }
};
