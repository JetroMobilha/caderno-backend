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
            if (!Schema::hasColumn('notebooks', 'line_spacing')) {
                $table->decimal('line_spacing', 5, 2)->nullable()->after('line_type')->default(28.00);
            }
            if (!Schema::hasColumn('notebooks', 'version')) {
                $table->bigInteger('version')->default(1);
            }
        });

        Schema::table('pages', function (Blueprint $table) {
            if (!Schema::hasColumn('pages', 'version')) {
                $table->bigInteger('version')->default(1);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            if (Schema::hasColumn('notebooks', 'line_spacing')) {
                $table->dropColumn('line_spacing');
            }
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
};
