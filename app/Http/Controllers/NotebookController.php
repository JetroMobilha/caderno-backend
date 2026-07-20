<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Subject;
use App\Models\Notebook;
use App\Models\User;
use App\Events\SyncRequested;

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
                ->whereNull('notebooks.deleted_at') // 🛡️ Correção de fantasmas
                ->select('notebooks.*', 'notebook_user.role')
                ->get()
                ->map(function($n) {
                    $n->subject_id = -1;
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
        ]);

        $notebook = $subject->notebooks()->create([
            'title'       => $request->title,
            'cover_type'  => $request->cover_type ?? 'color',
            'color'       => $request->color ?? '#0F4C5C',
            'line_type'   => $request->line_type ?? 'ruled',
            'paper_size'  => $request->paper_size ?? 'A4',
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

        $notebook->update($request->only(['title', 'cover_type', 'color', 'line_type', 'paper_size', 'price', 'is_published', 'description']));
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
        $notebook->delete();
        \App\Events\SyncRequested::dispatch($request->user()->id);
        return response()->json(['message' => 'Apagado.']);
    }

    // =========================================================================
    // 🤝 PARTILHAR CADERNO COM OUTRO UTILIZADOR (EDTECH)
    // =========================================================================
    public function share(Request $request, $id)
    {
        $request->validate([
            'email' => 'required|email',
            'role'  => 'required|in:editor,viewer,student'
        ]);

        // 1. Garante que quem está a partilhar é o dono absoluto
        $notebook = Notebook::whereHas('subject', function($q) use ($request) {
            $q->where('user_id', $request->user()->id);
        })->findOrFail($id);

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
            ['role' => $request->role, 'updated_at' => now()] // O Laravel trata da data
        );

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
            ->select('users.name', 'users.email', 'notebook_user.role')
            ->get();

        return response()->json($collaborators);
    }

    // =========================================================================
    // 🧨 C. REVOCOAR PERMISSÃO / REMOVER ACESSO
    // =========================================================================
    public function unshare(Request $request, $id)
    {
        $request->validate(['email' => 'required|email']);

        $notebook = Notebook::whereHas('subject', function($q) use ($request) {
            $q->where('user_id', $request->user()->id);
        })->findOrFail($id);

        $guest = \App\Models\User::where('email', $request->email)->firstOrFail();

        // Elimina o vínculo na tabela pivô
        DB::table('notebook_user')
            ->where('notebook_id', $notebook->id)
            ->where('user_id', $guest->id)
            ->delete();

        return response()->json(['message' => 'Acesso revogado com sucesso.']);
    }

    public function uploadImage(Request $request, $id) {
        $path = $request->file('image')->store('notebooks/images', 'public');
        return response()->json(['url' => asset('storage/' . $path)]);
    }
}