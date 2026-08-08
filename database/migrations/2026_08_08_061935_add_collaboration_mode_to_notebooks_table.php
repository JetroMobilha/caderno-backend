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
            $table->string('collaboration_mode')->default('study_group')->after('template_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notebooks', function (Blueprint $table) {
            $table->dropColumn('collaboration_mode');
        });
    }
};
