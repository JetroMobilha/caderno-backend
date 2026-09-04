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
            $table->longText('objects_data')->nullable()->after('image_data');

            // 🚀 LAYOUT E VIEWPORT
            $table->text('viewport_matrix')->nullable()->after('background_config');
            $table->text('layers')->nullable()->after('viewport_matrix');

            // 🚀 ESTADOS DE PÁGINA (v29)
            if (!Schema::hasColumn('pages', 'is_infinite')) {
                $table->boolean('is_infinite')->default(false)->after('is_frozen');
            }
            if (!Schema::hasColumn('pages', 'is_favorite')) {
                $table->boolean('is_favorite')->default(false)->after('is_infinite');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['objects_data', 'viewport_matrix', 'layers', 'is_infinite', 'is_favorite']);
        });
    }
};
