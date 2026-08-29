<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Page;
use App\Models\Notebook;
use App\Models\Subject;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CleanupTrash extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cleanup-trash';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Elimina permanentemente itens na lixeira há mais de 30 dias';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Iniciando limpeza definitiva da lixeira (Retention: 30 days)...');

        $threshold = Carbon::now()->subDays(30);
        $totalDeleted = 0;

        // 1. Limpar Páginas
        $pages = Page::onlyTrashed()->where('deleted_at', '<', $threshold)->get();
        foreach ($pages as $page) {
            $page->forceDelete();
            $totalDeleted++;
        }

        // 2. Limpar Cadernos
        $notebooks = Notebook::onlyTrashed()->where('deleted_at', '<', $threshold)->get();
        foreach ($notebooks as $notebook) {
            $notebook->forceDelete();
            $totalDeleted++;
        }

        // 3. Limpar Disciplinas
        $subjects = Subject::onlyTrashed()->where('deleted_at', '<', $threshold)->get();
        foreach ($subjects as $subject) {
            $subject->forceDelete();
            $totalDeleted++;
        }

        if ($totalDeleted > 0) {
            $msg = "🧹 Limpeza concluída: $totalDeleted itens eliminados permanentemente.";
            $this->info($msg);
            Log::info("[Cleanup] $msg");
        } else {
            $this->info('✅ Nada para limpar hoje.');
        }

        return Command::SUCCESS;
    }
}
