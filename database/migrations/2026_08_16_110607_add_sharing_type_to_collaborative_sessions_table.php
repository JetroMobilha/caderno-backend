<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collaborative_sessions', function (Blueprint $table) {
            $table->enum('sharing_type', ['full', 'scoped'])->default('full')->after('alternative_title');
        });
    }

    public function down(): void
    {
        Schema::table('collaborative_sessions', function (Blueprint $table) {
            $table->dropColumn('sharing_type');
        });
    }
};
