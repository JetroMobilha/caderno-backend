<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessRealtimeUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $pageData, // Agora recebe os dados diretamente
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
        $user = User::find($this->userId);
        if (!$user) {
            Log::warning("⚠️ [Realtime Job] Utilizador {$this->userId} não encontrado para persistência.");
            return;
        }

        try {
            // Sincronizar usando o serviço centralizado com os dados atómicos
            $result = $syncService->processPageData($this->pageData, $user, $this->userRole);

            if ($result) {
                Log::debug("✅ [Realtime Job] Persistência concluída para folha {$this->pageData['client_id']}.");
            }
        } catch (\Exception $e) {
            Log::error("🚨 [Realtime Job] Falha ao persistir folha {$this->pageData['client_id']}: " . $e->getMessage());
            throw $e;
        }
    }
}
