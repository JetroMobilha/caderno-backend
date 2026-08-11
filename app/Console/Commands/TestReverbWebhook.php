<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\CollaborativeSessionParticipant;
use App\Models\CollaborativeSession;

class TestReverbWebhook extends Command
{
    protected $signature = 'session:test-webhook {notebook_id=131} {user_id=2}';
    protected $description = 'Diagnóstico profundo do Webhook e Base de Dados';

    public function handle()
    {
        $notebookId = $this->argument('notebook_id');
        $userId = $this->argument('user_id');

        $url = config('reverb.apps.apps.0.webhooks.0.url');
        $this->info("🔍 URL configurado: $url");

        $payload = [
            'events' => [
                ['name' => 'member_added', 'channel' => "presence-notebook.$notebookId", 'user_id' => $userId]
            ]
        ];

        $this->warn("🚀 1. Testando chamada HTTPS (com bypass de SSL)...");
        try {
            $response = Http::withoutVerifying()->post($url, $payload);
            $this->line("📡 Resposta: HTTP " . $response->status());
        } catch (\Exception $e) {
            $this->error("❌ Erro na chamada: " . $e->getMessage());
        }

        $this->warn("\n📊 2. Estado da Base de Dados:");
        $activeSessions = CollaborativeSession::where('is_active', true)->count();
        $this->line("• Sessões Ativas: $activeSessions");

        $participants = CollaborativeSessionParticipant::whereNull('left_at')
            ->with('user')
            ->get();

        if ($participants->isEmpty()) {
            $this->error("• Nenhum utilizador online detetado na DB.");
        } else {
            $this->info("• Utilizadores Online (" . $participants->count() . "):");
            foreach ($participants as $p) {
                $userName = $p->user ? $p->user->name : "Desconhecido";
                $this->line("  - User ID: {$p->user_id} ($userName) | Entrou: {$p->joined_at}");
            }
        }

        $this->warn("\n💡 DICA:");
        $this->line("Se o 'curl -k' funcionou mas o Reverb automático não funciona,");
        $this->line("tente mudar REVERB_WEBHOOK_URL para http://127.0.0.1:porta (sem HTTPS) se o seu servidor tiver uma porta HTTP interna aberta.");
    }
}
