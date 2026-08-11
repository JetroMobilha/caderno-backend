<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessRealtimeUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $pageClientId,
        public int $userId,
        public string $userRole
    ) {
        $this->onQueue('realtime');
    }

    /**
     * Execute the job.
     */
    public function handle(SyncService $syncService): void
    {
        $redisKey = "page_update:{$this->pageClientId}";
        $updateData = Cache::get($redisKey);

        if (!$updateData) {
            return;
        }

        $user = User::find($this->userId);
        if (!$user) {
            Log::warning("⚠️ [Realtime Job] Utilizador {$this->userId} não encontrado para persistência.");
            return;
        }

        try {
            // Sincronizar usando o serviço centralizado
            $result = $syncService->processPageData($updateData, $user, $this->userRole);

            if ($result) {
                // Limpar cache após sucesso
                Cache::forget($redisKey);
                Log::debug("✅ [Realtime Job] Persistência concluída para folha {$this->pageClientId}.");
            }
        } catch (\Exception $e) {
            Log::error("🚨 [Realtime Job] Falha ao persistir folha {$this->pageClientId}: " . $e->getMessage());
            throw $e;
        }
    }
}
