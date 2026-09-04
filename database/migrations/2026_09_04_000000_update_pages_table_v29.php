<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            // 🚀 UNIFICAÇÃO: Campo para todos os objetos do canvas
            if (!Schema::hasColumn('pages', 'objects_data')) {
                $table->longText('objects_data')->nullable()->after('image_data');
            }

            // 🚀 LAYOUT E VIEWPORT
            if (!Schema::hasColumn('pages', 'viewport_matrix')) {
                $table->text('viewport_matrix')->nullable()->after('background_config');
            }

            if (!Schema::hasColumn('pages', 'layers')) {
                $table->text('layers')->nullable()->after('viewport_matrix');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['objects_data', 'viewport_matrix', 'layers']);
        });
    }
};
