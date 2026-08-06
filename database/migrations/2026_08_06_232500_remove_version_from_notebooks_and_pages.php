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
        Schema::table('notebooks', function (Blueprint $table) {
            if (Schema::hasColumn('notebooks', 'version')) {
                $table->dropColumn('version');
            }
        });

        Schema::table('pages', function (Blueprint $table) {
            if (Schema::hasColumn('pages', 'version')) {
                $table->dropColumn('version');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            $table->bigInteger('version')->default(1);
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->bigInteger('version')->default(1);
        });
    }
};
