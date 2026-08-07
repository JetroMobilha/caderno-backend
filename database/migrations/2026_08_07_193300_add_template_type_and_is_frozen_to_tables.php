<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notebooks', function (Blueprint $blueprint) {
            $blueprint->string('template_type')->default('study')->after('updated_at_ms');
        });

        Schema::table('pages', function (Blueprint $blueprint) {
            $blueprint->boolean('is_frozen')->default(false)->after('background_image_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notebooks', function (Blueprint $blueprint) {
            $blueprint->dropColumn('template_type');
        });

        Schema::table('pages', function (Blueprint $blueprint) {
            $blueprint->dropColumn('is_frozen');
        });
    }
};
