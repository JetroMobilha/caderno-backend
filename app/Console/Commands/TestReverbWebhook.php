<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\CollaborativeSessionParticipant;
use App\Models\CollaborativeSession;

class TestReverbWebhook extends Command
{
    protected $signature = 'session:test-webhook {notebook_id} {user_id}';
    protected $description = 'Simula um webhook do Reverb para testar o rastreio de sessão';

    public function handle()
    {
        $notebookId = $this->argument('notebook_id');
        $userId = $this->argument('user_id');

        $url = config('reverb.apps.apps.0.webhooks.0.url');
        if (!$url) {
            $this->error("❌ URL do Webhook não configurado em config/reverb.php");
            return;
        }

        $this->info("🚀 Testando Webhook em: $url");

        $payload = [
            'events' => [
                [
                    'name' => 'member_added',
                    'channel' => "presence-notebook.$notebookId",
                    'user_id' => $userId,
                ]
            ]
        ];

        try {
            $response = Http::post($url, $payload);

            if ($response->successful()) {
                $this->info("✅ Servidor respondeu com sucesso (HTTP " . $response->status() . ")");

                // Verificar se inseriu no banco
                $participant = CollaborativeSessionParticipant::where('user_id', $userId)
                    ->whereNull('left_at')
                    ->first();

                if ($participant) {
                    $this->info("✨ SUCESSO: Utilizador encontrado na tabela de participantes!");
                } else {
                    $this->warn("⚠️ Servidor respondeu OK, mas o utilizador não apareceu na DB. Verifique os logs do Laravel.");
                }
            } else {
                $this->error("❌ Falha na requisição: HTTP " . $response->status());
                $this->line($response->body());
            }
        } catch (\Exception $e) {
            $this->error("🚨 Erro ao tentar contactar o Webhook: " . $e->getMessage());
        }
    }
}
