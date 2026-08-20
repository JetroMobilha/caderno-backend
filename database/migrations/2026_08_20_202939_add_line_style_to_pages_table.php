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
            if (!Schema::hasColumn('pages', 'line_type')) {
                $table->string('line_type')->nullable()->after('extracted_text');
            }
            if (!Schema::hasColumn('pages', 'line_spacing')) {
                $table->decimal('line_spacing', 8, 2)->nullable()->after('line_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['line_type', 'line_spacing']);
        });
    }
};
