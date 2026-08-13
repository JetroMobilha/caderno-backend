<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\NotebookController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\WebRtcController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\MarketplaceController;
use App\Http\Controllers\AIAssistantController;
use App\Http\Controllers\HandwritingRecognitionController;
use App\Http\Controllers\Api\HandwritingSynthesisController;
use App\Http\Controllers\Api\UserAlphabetController;

/*
|--------------------------------------------------------------------------
| 🔓 ROTAS PÚBLICAS (Acesso Livre)
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Webhook de Pagamentos (A ProxyPay chama esta rota livremente)
Route::post('/webhooks/payment-confirmation', [PaymentController::class, 'webhookConfirmation']);

// 🔍 Rota de Diagnóstico Temporária
    Route::get('/debug/reverb-config', function() {
        return response()->json(config('reverb.apps.apps.0.webhooks'));
    });

// 📡 Webhooks do Reverb (Pusher compatible)
Route::post('/webhooks/reverb', [App\Http\Controllers\CollaborativeSessionController::class, 'webhook']);

/*
|--------------------------------------------------------------------------
| 🔒 ROTAS PROTEGIDAS (Acesso via Token Sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // 📡 WebSockets & Channels
    Broadcast::routes();
    require base_path('routes/channels.php');

    // 👤 Perfil de Utilizador & Autenticação
    Route::get('/user', function (Request $request) { return $request->user(); });
    Route::post('/user/update', [AuthController::class, 'updateProfile']);
    Route::get('/users/search', [AuthController::class, 'searchUsers']); // 🚀 Movido para o domínio Auth!
    Route::post('/logout', [AuthController::class, 'logout']);

    // 💰 Pagamentos
    Route::post('/pay/multicaixa', [PaymentController::class, 'generateReference']);

    // 📚 Disciplinas (CRUD)
    Route::apiResource('subjects', SubjectController::class);

    // 📓 Cadernos (CRUD Clássico e Exportação)
    Route::get('/subjects/{subject_id}/notebooks', [NotebookController::class, 'index']);
    Route::post('/subjects/{subject_id}/notebooks', [NotebookController::class, 'store']);
    Route::put('/notebooks/{notebook}', [NotebookController::class, 'update']);
    Route::delete('/notebooks/{notebook}', [NotebookController::class, 'destroy']);
    Route::get('/notebooks/{notebook}/export-pdf', [NotebookController::class, 'exportPdf']);
    Route::post('/notebooks/import-pdf', [NotebookController::class, 'importPdf']);

    // 🤝 Cadernos Partilhados (Ações EdTech)
    Route::get('/notebooks/shared/unread-count', function (Request $request) {
        return response()->json(['unread_shares' => $request->user()->sharedNotebooks()->count()]);
    });
    Route::get('/notebooks/{id}/collaborators', [NotebookController::class, 'getCollaborators']);
    Route::post('/notebooks/{id}/share', [NotebookController::class, 'share']);
    Route::delete('/notebooks/{id}/share', [NotebookController::class, 'unshare']);

    // ✍️ Páginas dos Cadernos
    Route::get('/notebooks/{notebook_id}/pages', [PageController::class, 'index']);
    Route::post('/notebooks/{notebook_id}/pages', [PageController::class, 'store']);
    Route::post('/notebooks/{notebook_id}/upload-image', [NotebookController::class, 'uploadImage']);
    // 🎥 WebRTC (Colaboração em Tempo Real)
    Route::post('/notebooks/{notebook_id}/webrtc/signal', [WebRtcController::class, 'signal']);
    Route::post('/notebooks/{notebook}/upload-audio', [NotebookController::class, 'uploadAudio']);
    Route::get('/notebooks/{notebook}/lesson-recordings', [NotebookController::class, 'getLessonRecordings']);

    // =========================================================================
    // 🚀 MOTOR DE SINCRONIZAÇÃO OFFLINE-FIRST (MOBILE/DESKTOP)
    // =========================================================================
    Route::post('/sync/push', [SyncController::class, 'push']);
    Route::get('/sync/pull', [SyncController::class, 'pull']);

    Route::post('/sync/notebooks/push', [SyncController::class, 'pushNotebooks']);
    Route::get('/sync/notebooks/pull', [SyncController::class, 'pullNotebooks']);

    Route::post('/sync/pages/push', [SyncController::class, 'pushPages']);
    Route::get('/sync/pages/pull', [SyncController::class, 'pullPages']);

    Route::post('/sync/recordings/push', [SyncController::class, 'pushRecordings']);
    Route::get('/sync/recordings/pull', [SyncController::class, 'pullRecordings']);
    Route::post('/sync/realtime/update', [SyncController::class, 'realtimeUpdate']);


    // Listar e pesquisar cadernos na loja
    Route::get('/marketplace/notebooks', [MarketplaceController::class, 'index']);

    // Adquirir (Clonar) um caderno para a conta do estudante
    Route::post('/marketplace/notebooks/{id}/acquire', [MarketplaceController::class, 'acquire']);

    // 🧠 IA Assistant
    Route::post('/ai/search', [AIAssistantController::class, 'search']);
    Route::post('/ai/summarize', [AIAssistantController::class, 'summarize']);

    // 🔎 Pesquisa Global (OCR)
    Route::get('/search', [App\Http\Controllers\SearchController::class, 'globalSearch']);

    // ✍️ Reconhecimento de escrita manual (gratuito, local)
    Route::post('/handwriting/recognize', [HandwritingRecognitionController::class, 'recognize']);

    // ✍️ Síntese de escrita manual (texto para traços vetoriais)
    Route::post('/handwriting/synthesize', [App\Http\Controllers\HandwritingSynthesisController::class, 'synthesize']);

    // 🖋️ Armazenar o alfabeto personalizado do utilizador
    Route::post('/handwriting/alphabet', [UserAlphabetController::class, 'store']);

    // 🤝 Gestão de Sessões Colaborativas (Arquitetura API-First)
    Route::post('/notebooks/{id}/session/join', [App\Http\Controllers\CollaborativeSessionController::class, 'join']);
    Route::post('/notebooks/{id}/session/heartbeat', [App\Http\Controllers\CollaborativeSessionController::class, 'heartbeat']);
    Route::post('/notebooks/{id}/session/leave', [App\Http\Controllers\CollaborativeSessionController::class, 'leave']);
    Route::get('/notebooks/{id}/session/status', [App\Http\Controllers\CollaborativeSessionController::class, 'getStatus']);
});


