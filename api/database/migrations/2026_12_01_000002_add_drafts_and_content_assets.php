<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4.2: Course Studio. HQ edits a draft; publishing copies it to the live content learners see and
 * raises content_version. content_assets holds pictures, narration audio and videos (no personal data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->jsonb('draft')->nullable(); // {blocks, i18n: {zu: {blockId: {field: text}}}, review: {en|zu|..: {by, at, ai}}}
            $table->timestamp('draft_updated_at')->nullable();
        });

        Schema::create('content_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 8); // image · audio · video
            $table->string('path', 255);
            $table->string('mime', 60);
            $table->unsignedInteger('bytes');
            $table->char('sha256', 64)->unique();
            $table->string('name', 200)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_assets');
        Schema::table('activities', fn (Blueprint $t) => $t->dropColumn(['draft', 'draft_updated_at']));
    }
};
