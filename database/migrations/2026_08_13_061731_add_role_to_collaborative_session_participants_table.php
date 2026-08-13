<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collaborative_session_participants', function (Blueprint $table) {
            $table->string('role')->default('student')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('collaborative_session_participants', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
