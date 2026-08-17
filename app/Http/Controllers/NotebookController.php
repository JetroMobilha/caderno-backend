<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Subject;
use App\Models\Notebook;
use App\Models\User;
use App\Events\SyncRequested;
use App\Events\NotebookDeleted;
use App\Models\CollaborativeSession;
use App\Models\CollaborativeSessionPage;
use Spatie\PdfToImage\Pdf;
use Illuminate\Support\Facades\Storage;
use App\Models\Page;

class NotebookController extends Controller
{
    // =========================================================================
    // 📚 LISTAR CADERNOS
    // =========================================================================
    public function index(Request $request, $subject_id)
    {
        $user = $request->user();

        // Aba de Partilhados
        if ($subject_id == -1) {
            $shared = DB::table('notebooks')
                ->join('notebook_user', 'notebooks.id', '=', 'notebook_user.notebook_id')
                ->where('notebook_user.user_id', $user->id)
                ->whereNull('notebooks.deleted_at')
                ->select('notebooks.*', 'notebook_user.role')
                ->get()
                ->map(function($n) {
                    $n->subject_id = -1;

                    // 🚀 Adicionar metadados de sessão viva
                    $session = CollaborativeSession::where('notebook_id', $n->id)
                        ->where('is_active', true)
                        ->first();

                    $n->alternative_title = $session ? $session->alternative_title : null;
                    $n->sharing_type = $session ? $session->sharing_type : 'full';

                    return $n;
                });
            return response()->json($shared);
        }

        // Próprios
        $subject = $user->subjects()->findOrFail($subject_id);
        $notebooks = $subject->notebooks->map(function($n) {
            $n->role = 'owner';
            return $n;
        });

        return response()->json($notebooks);
    }

    // =========================================================================
    // ➕ CRIAR CADERNO
    // =========================================================================
    public function store(Request $request, $subject_id)
    {
        $subject = $request->user()->subjects()->findOrFail($subject_id);

        $request->validate([
            'title'       => 'required|string|max:255',
            'cover_type'  => 'nullable|string',
            'color'       => 'nullable|string|max:50',
            'line_type'   => 'nullable|string|max:50',
            'paper_size'  => 'nullable|string|max:10',
            'line_spacing' => 'nullable|numeric|min:10|max:150',
        ]);

        $notebook = $subject->notebooks()->create([
            'title'       => $request->title,
            'cover_type'  => $request->cover_type ?? 'color',
            'color'       => $request->color ?? '#0F4C5C',
            'line_type'   => $request->line_type ?? 'ruled',
            'paper_size'  => $request->paper_size ?? 'A4',
            'line_spacing' => $request->line_spacing ?? 28,
        ]);

        SyncRequested::dispatch($request->user()->id);

        return response()->json($notebook, 201);
    }

    // =========================================================================
    // ✏️ ATUALIZAR CADERNO (Web/Síncrono)
    // =========================================================================
    public function update(Request $request, $id)
    {
        $notebook = Notebook::findOrFail($id);

        // Verifica se é dono ou editor
        $isOwner = $notebook->subject()->where('user_id', $request->user()->id)->exists();
        $isEditor = DB::table('notebook_user')->where('notebook_id', $id)->where('user_id', $request->user()->id)->where('role', 'editor')->exists();

        if (!$isOwner && !$isEditor) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $updateData = $request->only(['title', 'cover_type', 'color', 'line_type', 'paper_size', 'price', 'is_published', 'description', 'line_spacing', 'subject_id']);

        // 🛡️ Segurança: Validar se a nova disciplina pertence ao utilizador
        if (isset($updateData['subject_id'])) {
            $subject = Subject::where('user_id', $request->user()->id)->find($updateData['subject_id']);
            if (!$subject) {
                return response()->json(['message' => 'Disciplina inválida.'], 400);
            }
        }

        $notebook->update($updateData);
        SyncRequested::dispatch($request->user()->id);
        return response()->json($notebook, 200);
    }

    // =========================================================================
    // 🗑️ APAGAR CADERNO
    // =========================================================================
    public function destroy(Request $request, $id)
    {
        $notebook = Notebook::findOrFail($id);
        if ($notebook->subject->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Sem permissão.'], 403);
        }

        // 🚀 Notificar colaboradores antes de apagar
        try {
            NotebookDeleted::dispatch($notebook);
        } catch (\Exception $e) {}

        $notebook->delete();
        \App\Events\SyncRequested::dispatch($request->user()->id);
        return response()->json(['message' => 'Apagado.']);
    }

    // =========================================================================
    // 🖨️ IMPORTAR CADERNO A PARTIR DE PDF
    // =========================================================================
    public function importPdf(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf|max:25600', // max 25MB
            'subject_id' => 'required|integer|exists:subjects,id'
        ]);

        $user = $request->user();
        $subject = $user->subjects()->findOrFail($request->subject_id);
        $file = $request->file('file');

        // 1. Criar o caderno
        $notebook = $subject->notebooks()->create([
            'title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'user_id' => $user->id, // Associar ao usuário
        ]);

        // 2. Preparar para processar o PDF
        $pdf = new Pdf($file->getRealPath());
        $totalPages = $pdf->getNumberOfPages();

        // Diretório para as imagens de fundo
        $storagePath = "public/notebooks/{$notebook->id}/backgrounds";
        if(!Storage::exists($storagePath)) {
            Storage::makeDirectory($storagePath);
        }

        // 3. Processar cada página
        for ($i = 1; $i <= $totalPages; $i++) {
            $imageName = "page_{$i}.png";
            $imagePath = storage_path("app/{$storagePath}/{$imageName}");

            $pdf->setPage($i)->saveImage($imagePath);

            // 4. Criar a página no banco de dados
            $notebook->pages()->create([
                'page_number' => $i,
                'background_image_path' => "notebooks/{$notebook->id}/backgrounds/{$imageName}", // Caminho relativo para o asset
                'paper_size' => null, // paper_size pode ser extraído se a biblioteca suportar ou definido como null
            ]);
        }

        // Carregar as páginas recém-criadas para a resposta
        $notebook->load('pages');

        SyncRequested::dispatch($user->id);

        return response()->json($notebook, 201);
    }

    // =========================================================================
    // 🤝 PARTILHAR CADERNO COM OUTRO UTILIZADOR (EDTECH)
    // =========================================================================
    public function share(Request $request, $id)
    {
        $request->validate([
            'email' => 'required|email',
            'role'  => 'required|in:editor,viewer,student',
            'sharing_type' => 'nullable|string|in:full,scoped',
            'alternative_title' => 'nullable|string',
            'page_ids' => 'nullable|array'
        ]);

        $user = $request->user();
        $notebook = Notebook::findOrFail($id);

        // 🛡️ PERMISSÃO: Apenas dono ou editor pode partilhar com terceiros
        $isOwner = $notebook->subject && $notebook->subject->user_id === $user->id;
        $isEditor = DB::table('notebook_user')
            ->where('notebook_id', $id)
            ->where('user_id', $user->id)
            ->where('role', 'editor')
            ->exists();

        if (!$isOwner && !$isEditor) {
            return response()->json(['message' => 'Não tens permissão para partilhar este caderno.'], 403);
        }

        // 2. Procura o convidado pelo e-mail
        $guest = User::where('email', $request->email)->first();
        if (!$guest) {
            return response()->json(['message' => 'Utilizador não encontrado no sistema.'], 404);
        }

        if ($guest->id === $request->user()->id) {
            return response()->json(['message' => 'Não podes partilhar o caderno contigo mesmo.'], 400);
        }

        // 3. Insere ou atualiza o convite na Tabela Pivô (notebook_user)
        DB::table('notebook_user')->updateOrInsert(
            ['notebook_id' => $notebook->id, 'user_id' => $guest->id],
            ['role' => $request->role, 'updated_at' => now()]
        );

        // 🚀 NOTIFICAR O CONVIDADO QUE TEM UM NOVO CADERNO
        try {
            \App\Events\SyncRequested::dispatch($guest->id);
        } catch (\Exception $e) {}

        // 🚀 4. CONFIGURAR SESSÃO INICIAL SE PARAMETRIZADA (Privacidade por Folhas)
        if ($request->has('sharing_type')) {
            $session = CollaborativeSession::firstOrCreate(
                ['notebook_id' => $notebook->id, 'is_active' => true],
                ['started_at' => now()]
            );

            $session->update([
                'sharing_type' => $request->sharing_type,
                'alternative_title' => $request->alternative_title
            ]);

            if ($request->sharing_type === 'scoped' && $request->has('page_ids')) {
                foreach ($request->page_ids as $pid) {
                    CollaborativeSessionPage::updateOrCreate([
                        'session_id' => $session->id,
                        'page_id' => $pid
                    ]);
                }
            }
        }

        return response()->json(['message' => 'Caderno partilhado com sucesso!']);
    }

    // =========================================================================
    // 👥 B. LISTAR COLABORADORES ATUAIS DO CADERNO
    // =========================================================================
    public function getCollaborators(Request $request, $id)
    {
        $notebook = Notebook::whereHas('subject', function($q) use ($request) {
            $q->where('user_id', $request->user()->id);
        })->findOrFail($id);

        // Busca todos os convidados na tabela pivô
        $collaborators = DB::table('users')
            // 🚀 CORREÇÃO AQUI: users.id cruza com notebook_user.user_id !
            ->join('notebook_user', 'users.id', '=', 'notebook_user.user_id')
            ->where('notebook_user.notebook_id', $notebook->id)
            ->select('users.id', 'users.name', 'users.email', 'notebook_user.role')
            ->get();

        return response()->json($collaborators);
    }

    // =========================================================================
    // 🧨 C. REVOCOAR PERMISSÃO / REMOVER ACESSO
    // =========================================================================
    public function unshare(Request $request, $id)
    {
        $request->validate(['email' => 'required|email']);
        $user = $request->user();
        $notebook = Notebook::findOrFail($id);

        $targetUser = \App\Models\User::where('email', $request->email)->firstOrFail();

        // 🛡️ LÓGICA DE SEGURANÇA:
        // 1. O dono pode remover qualquer um.
        // 2. Um convidado pode remover-se a si próprio ("Sair").
        $isOwner = $notebook->subject && $notebook->subject->user_id === $user->id;
        $isSelf = $targetUser->id === $user->id;

        if (!$isOwner && !$isSelf) {
            return response()->json(['message' => 'Não tens permissão para remover este utilizador.'], 403);
        }

        // Elimina o vínculo na tabela pivô
        DB::table('notebook_user')
            ->where('notebook_id', $notebook->id)
            ->where('user_id', $targetUser->id)
            ->delete();

        // 🚀 NOTIFICAR O UTILIZADOR QUE O ACESSO FOI REVOGADO
        try {
            \App\Events\NotebookAccessRevoked::dispatch($notebook, $targetUser->id);
        } catch (\Exception $e) {}

        return response()->json(['message' => $isSelf ? 'Saíste do caderno com sucesso.' : 'Acesso revogado com sucesso.']);
    }

    public function uploadImage(Request $request, $id) {
        $path = $request->file('image')->store('notebooks/images', 'public');
        return response()->json(['url' => asset('storage/' . $path)]);
    }

    public function uploadAudio(Request $request, Notebook $notebook) {
        $request->validate([
            'audio' => 'required|file',
            'title' => 'nullable|string|max:255',
            'duration' => 'nullable|integer',
            'client_id' => 'nullable|string',
        ]);

        $path = $request->file('audio')->store('lesson_recordings', 'public');
        $audioUrl = asset('storage/' . $path);

        // Se for uma gravação de aula (com título), criamos o registro na nova tabela
        if ($request->has('title')) {
            $notebook->lessonRecordings()->create([
                'title' => $request->title,
                'audio_url' => $audioUrl,
                'duration_seconds' => $request->duration ?? 0,
                'client_id' => $request->client_id,
                'updated_at_ms' => round(microtime(true) * 1000),
            ]);
        }

        return response()->json(['url' => $audioUrl]);
    }

    public function getLessonRecordings(Notebook $notebook) {
        return response()->json($notebook->lessonRecordings);
    }
}
