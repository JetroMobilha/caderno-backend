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
            $table->dropColumn(['line_type', 'paper_size', 'line_spacing']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            $table->string('line_type')->nullable()->after('cover_image');
            $table->string('paper_size')->nullable()->after('line_type');
            $table->decimal('line_spacing', 8, 2)->nullable()->after('paper_size');
        });
    }
};
