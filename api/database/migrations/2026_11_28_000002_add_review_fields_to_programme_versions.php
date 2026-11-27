<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Review feedback and who submitted a draft (for independent review). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programme_versions', function (Blueprint $table) {
            $table->uuid('submitted_by')->nullable()->after('submitted_at');
            $table->text('review_comment')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('programme_versions', function (Blueprint $table) {
            $table->dropColumn(['submitted_by', 'review_comment']);
        });
    }
};
