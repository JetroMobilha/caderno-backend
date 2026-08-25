<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\CollaborativeSession;
use App\Models\CollaborativeSessionPage;
use App\Models\Subject;
use App\Models\Notebook;
use App\Models\Page;
use App\Models\LessonRecording;
use App\Events\NotebookDeleted;

class SyncController extends Controller
{
    public function __construct(protected SyncService $syncService) {}

    // =========================================================================
    // 📚 1. SINCRONIZAÇÃO DE DISCIPLINAS
    // =========================================================================
    public function push(Request $request)
    {
        $user = $request->user();
        $clientSubjects = $request->input('subjects', []);
        $lastSyncedAt = $request->input('last_synced_at');
        $syncedSubjects = [];

        foreach ($clientSubjects as $data) {
            $incomingTime = (int)($data['updated_at'] ?? 0);
            $subject = Subject::withTrashed()->where('user_id', $user->id)
                ->where(function($q) use ($data) {
                    if (!empty($data['server_id'])) $q->where('id', $data['server_id']);
                    else $q->where('client_id', $data['client_id']);
                })->first();

            if (!empty($data['is_deleted']) && $data['is_deleted'] == 1) {
                if ($subject && !$subject->trashed() && $incomingTime > ($subject->updated_at_ms ?? 0)) {
                    $subject->update(['updated_at_ms' => $incomingTime]);
                    $subject->delete();
                }
                continue;
            }

            if ($subject) {
                if ($incomingTime >= ($subject->updated_at_ms ?? 0)) {
                    if ($subject->trashed()) $subject->restore();
                    $subject->update([
                        'name' => trim($data['name'] ?? $subject->name),
                        'color' => $data['color'] ?? $subject->color,
                        'icon' => $data['icon'] ?? $subject->icon,
                        'updated_at_ms' => $incomingTime,
                    ]);
                }
            } else {
                $subject = Subject::create([
                    'user_id' => $user->id,
                    'client_id' => $data['client_id'],
                    'name' => trim($data['name'] ?? 'Nova Disciplina'),
                    'color' => $data['color'] ?? '#000000',
                    'icon' => $data['icon'] ?? 'default-icon',
                    'updated_at_ms' => $incomingTime,
                ]);
            }
            $syncedSubjects[] = $subject->toArray();
        }

        $query = Subject::withTrashed()->where('user_id', $user->id);
        if ($lastSyncedAt) $query->where('updated_at', '>', $lastSyncedAt);

        $totalPending = $query->count();
        $serverUpdates = $query->limit(50)->get();

        return response()->json([
            'message' => 'OK',
            'synced_subjects' => $syncedSubjects,
            'server_updates' => $serverUpdates,
            'has_more' => $totalPending > 50,
            'total_pending' => $totalPending,
            'meta' => [
                'server_time' => now()->toIso8601String(),
                'server_time_ms' => (int)(microtime(true) * 1000)
            ]
        ]);
    }

    public function pull(Request $request)
    {
        $user = $request->user();
        $lastSyncedAt = $request->query('last_synced_at');
        $query = Subject::withTrashed()->where('user_id', $user->id);
        if ($lastSyncedAt) $query->where('updated_at', '>', $lastSyncedAt);
        $paginated = $query->paginate(50);
        return response()->json([
            'data' => $paginated->items(),
            'links' => $paginated->linkCollection(),
            'meta' => [
                'server_time' => now()->toIso8601String(),
                'server_time_ms' => (int)(microtime(true) * 1000)
            ]
        ]);
    }

    // =========================================================================
    // 📓 2. SINCRONIZAÇÃO DE CADERNOS
    // =========================================================================
    public function pushNotebooks(Request $request)
    {
        $user = $request->user();
        $lastSyncedAt = $request->input('last_synced_at');
        $syncedNotebooks = [];

        foreach ($request->input('notebooks', []) as $data) {
            $incomingTime = (int)($data['updated_at'] ?? 0);
            $notebook = Notebook::withTrashed()->where(function($q) use ($data) {
                if (!empty($data['server_id'])) $q->where('id', $data['server_id']);
                else $q->where('client_id', $data['client_id']);
            })->first();

            if ($notebook) {
                $role = ($notebook->subject && $notebook->subject->user_id === $user->id) ? 'owner' :
                    (DB::table('notebook_user')->where('notebook_id', $notebook->id)->where('user_id', $user->id)->value('role') ?? 'viewer');
                if ($role === 'viewer' || $role === 'student') continue;
            }

            if (!empty($data['is_deleted']) && $data['is_deleted'] == 1) {
                if ($notebook && !$notebook->trashed() && $notebook->subject && $notebook->subject->user_id == $user->id) {
                    if ($incomingTime > ($notebook->updated_at_ms ?? 0)) {
                        $notebook->update(['updated_at_ms' => $incomingTime]);
                        NotebookDeleted::dispatch($notebook);
                        $notebook->delete();
                    }
                }
                continue;
            }

            $fields = ['client_id','subject_id','title','color','template_type','collaboration_mode','line_type','paper_size','updated_at_ms'];
            $updateData = collect($data)->only($fields)->toArray();
            $updateData['updated_at_ms'] = $incomingTime;

            if ($notebook) {
                if ($incomingTime >= ($notebook->updated_at_ms ?? 0)) {
                    if ($notebook->trashed()) $notebook->restore();
                    $notebook->update($updateData);
                }
            } else {
                $notebook = Notebook::create($updateData);
            }
            $syncedNotebooks[] = $notebook->toArray();
        }

        $query = Notebook::withTrashed()->where(function ($q) use ($user) {
            $q->whereHas('subject', fn($sub) => $sub->where('user_id', $user->id))
              ->orWhereHas('sharedUsers', fn($shared) => $shared->where('user_id', $user->id));
        });

        if ($lastSyncedAt) $query->where('updated_at', '>', $lastSyncedAt);

        $totalPending = $query->count();
        $serverUpdates = $query->limit(50)->get()->map(function ($nb) use ($user) {
            $nb->role = ($nb->subject && $nb->subject->user_id === $user->id) ? 'owner' :
                (DB::table('notebook_user')->where('notebook_id', $nb->id)->where('user_id', $user->id)->value('role') ?? 'viewer');
            return $nb;
        });

        return response()->json([
            'message' => 'OK',
            'synced_notebooks' => $syncedNotebooks,
            'server_updates' => $serverUpdates,
            'has_more' => $totalPending > 50,
            'total_pending' => $totalPending,
            'meta' => [
                'server_time' => now()->toIso8601String(),
                'server_time_ms' => (int)(microtime(true) * 1000)
            ]
        ]);
    }

    public function pullNotebooks(Request $request)
    {
        $user = $request->user();
        $lastSyncedAt = $request->query('last_synced_at');
        $query = Notebook::withTrashed()->where(function ($q) use ($user) {
            $q->whereHas('subject', fn($sub) => $sub->where('user_id', $user->id))
              ->orWhereHas('sharedUsers', fn($shared) => $shared->where('user_id', $user->id));
        });
        if ($lastSyncedAt) $query->where('updated_at', '>', $lastSyncedAt);
        $paginated = $query->paginate(50);
        $items = collect($paginated->items())->map(function ($nb) use ($user) {
            $nb->role = ($nb->subject && $nb->subject->user_id === $user->id) ? 'owner' : 'viewer';
            return $nb;
        });
        return response()->json([
            'data' => $items,
            'links' => $paginated->linkCollection(),
            'meta' => [
                'server_time' => now()->toIso8601String(),
                'server_time_ms' => (int)(microtime(true) * 1000)
            ]
        ]);
    }

    // =========================================================================
    // ✍️ 3. SINCRONIZAÇÃO DE PÁGINAS
    // =========================================================================
    public function pushPages(Request $request)
    {
        $user = $request->user();
        $clientPages = $request->input('pages', []);
        $syncedPages = [];
        DB::transaction(function () use ($user, $clientPages, &$syncedPages) {
            foreach ($clientPages as $pageData) {
                $res = $this->syncService->processPageData($pageData, $user);
                if ($res) $syncedPages[] = $res;
            }
        });
        return response()->json([
            'message' => 'OK',
            'synced_pages' => $syncedPages,
            'meta' => [
                'server_time' => now()->toIso8601String(),
                'server_time_ms' => (int)(microtime(true) * 1000)
            ]
        ]);
    }

    public function pullPages(Request $request)
    {
        $user = $request->user();
        $notebookId = $request->query('notebook_id');
        $lastSyncedAt = $request->query('last_synced_at');

        $query = Page::withTrashed()->whereHas('notebook', function ($q) use ($user) {
            $q->whereHas('subject', fn($s) => $s->where('user_id', $user->id))
              ->orWhereHas('sharedUsers', fn($s) => $s->where('user_id', $user->id));
        });
        if ($notebookId) $query->where('notebook_id', $notebookId);
        if ($lastSyncedAt) $query->where('updated_at', '>', $lastSyncedAt);

        $paginated = $query->orderBy('page_number')->paginate(50);
        return response()->json([
            'data' => $paginated->items(),
            'links' => $paginated->linkCollection(),
            'meta' => [
                'server_time' => now()->toIso8601String(),
                'server_time_ms' => (int)(microtime(true) * 1000)
            ]
        ]);
    }

    // =========================================================================
    // 🎙️ 4. SINCRONIZAÇÃO DE GRAVAÇÕES
    // =========================================================================
    public function pushRecordings(Request $request)
    {
        $user = $request->user();
        $recordings = $request->input('recordings', []);
        $synced = [];
        foreach ($recordings as $data) {
            $rec = LessonRecording::withTrashed()->where('client_id', $data['client_id'])->first();
            if ($rec && !empty($data['is_deleted'])) { $rec->delete(); continue; }
            if (!$rec) $rec = LessonRecording::create($data);
            else $rec->update($data);
            $synced[] = $rec;
        }
        return response()->json(['message' => 'OK', 'synced_recordings' => $synced]);
    }

    public function pullRecordings(Request $request)
    {
        $user = $request->user();
        $query = LessonRecording::whereHas('notebook', fn($q) => $q->whereHas('subject', fn($s) => $s->where('user_id', $user->id)));
        return response()->json(['data' => $query->get()]);
    }

    public function realtimeUpdate(Request $request)
    {
        $user = $request->user();
        $pageData = $request->input('page');
        \App\Jobs\ProcessRealtimeUpdate::dispatch($pageData, $user->id, 'owner');
        return response()->json(['status' => 'queued']);
    }
}
