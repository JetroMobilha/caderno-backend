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
        // 1. Adicionar voice_mode à tabela collaborative_sessions
        Schema::table('collaborative_sessions', function (Blueprint $table) {
            // 'open' = qualquer um fala, 'authority_only' = dono/editor/autoridade, 'muted' = ninguém fala
            $table->string('voice_mode')->default('open')->after('alternative_title');
        });

        // 2. Adicionar can_speak à tabela collaborative_session_participants
        Schema::table('collaborative_session_participants', function (Blueprint $table) {
            $table->boolean('can_speak')->default(true)->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collaborative_sessions', function (Blueprint $table) {
            $table->dropColumn('voice_mode');
        });

        Schema::table('collaborative_session_participants', function (Blueprint $table) {
            $table->dropColumn('can_speak');
        });
    }
};
