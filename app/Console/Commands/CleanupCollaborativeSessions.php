<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CollaborativeSessionParticipant;
use App\Models\CollaborativeSession;
use Carbon\Carbon;

class CleanupCollaborativeSessions extends Command
{
    protected $signature = 'session:cleanup';
    protected $description = 'Limpa participantes inativos e encerra sessões vazias';

    public function handle()
    {
        $this->info('🚀 Iniciando limpeza de sessões...');

        // 1. Marcar como "saíram" participantes sem heartbeat há mais de 5 minutos
        // 🚀 Aumentamos para 5 minutos para evitar que relógios dessincronizados fechem sessões
        $threshold = Carbon::now()->subMinutes(5);

        Log::info("🧹 [Cleanup] Verificando heartbeats anteriores a: " . $threshold->toDateTimeString());

        $expiredCount = CollaborativeSessionParticipant::whereNull('left_at')
            ->where('last_heartbeat', '<', $threshold)
            ->update(['left_at' => Carbon::now()]);

        if ($expiredCount > 0) {
            $this->warn("🧹 $expiredCount participantes inativos foram removidos.");
        }

        // 2. Encerrar sessões ativas que não têm mais participantes online
        $activeSessions = CollaborativeSession::where('is_active', true)->get();
        $closedCount = 0;

        foreach ($activeSessions as $session) {
            if ($session->activeParticipants()->count() === 0) {
                $session->update([
                    'is_active' => false,
                    'ended_at' => Carbon::now()
                ]);
                $closedCount++;
            }
        }

        if ($closedCount > 0) {
            $this->info("✅ $closedCount sessões vazias foram encerradas.");
        }

        $this->info('✨ Limpeza concluída.');
    }
}
