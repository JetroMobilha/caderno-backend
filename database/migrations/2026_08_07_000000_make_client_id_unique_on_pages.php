<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 🛡️ Limpeza de segurança: Se houver duplicatas acidentais de client_id,
        // mantemos apenas a mais recente antes de aplicar a restrição UNIQUE.
        $duplicates = DB::table('pages')
            ->select('client_id')
            ->whereNotNull('client_id')
            ->groupBy('client_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('client_id');

        foreach ($duplicates as $clientId) {
            $latestId = DB::table('pages')
                ->where('client_id', $clientId)
                ->orderBy('updated_at', 'desc')
                ->value('id');

            DB::table('pages')
                ->where('client_id', $clientId)
                ->where('id', '<>', $latestId)
                ->delete();
        }

        Schema::table('pages', function (Blueprint $table) {
            // Remove o índice simples e adiciona a restrição UNIQUE
            $table->dropIndex(['client_id']);
            $table->unique('client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropUnique(['client_id']);
            $table->index('client_id');
        });
    }
};
