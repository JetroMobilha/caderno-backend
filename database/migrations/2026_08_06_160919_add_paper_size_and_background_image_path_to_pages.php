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
        Schema::table('pages', function (Blueprint $table) {
            $table->string('paper_size')->nullable()->after('stroke_data');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->string('background_image_path')->nullable()->after('paper_size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('paper_size');
            $table->dropColumn('background_image_path');
        });
    }
};
