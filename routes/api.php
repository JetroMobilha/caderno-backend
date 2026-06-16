<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\NotebookController;
use App\Http\Controllers\NotebookShareController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\WebRtcController;

/*
|--------------------------------------------------------------------------
| Rotas Públicas (Qualquer um pode aceder)
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1'); // Proteção Força Bruta
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Rota pública para a API de pagamentos (Proxypay) avisar o nosso servidor
Route::post('/webhooks/payment-confirmation', [PaymentController::class, 'webhookConfirmation']);


/*
|--------------------------------------------------------------------------
| Rotas Protegidas (Só entra quem enviar o Token válido)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    
    // Autenticação dos Canais de Tempo Real (WebRTC / Pusher / Reverb)
    Broadcast::routes(); 
    require base_path('routes/channels.php');
    
    // Utilizador
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Pagamentos
    Route::post('/pay/multicaixa', [PaymentController::class, 'generateReference']);
    
    // Disciplinas
    Route::apiResource('subjects', SubjectController::class);

    // Cadernos (Criar e Listar por Disciplina)
    Route::get('/subjects/{subject_id}/notebooks', [NotebookController::class, 'index']);
    Route::post('/subjects/{subject_id}/notebooks', [NotebookController::class, 'store']);
    
    // Cadernos (Ações diretas usando o ID do Caderno)
    Route::delete('/notebooks/{notebook}', [NotebookController::class, 'destroy']);
    Route::get('/notebooks/{notebook}/export-pdf', [NotebookController::class, 'exportPdf']);

    // Partilha de Cadernos
    Route::post('/notebooks/{notebook}/share', [NotebookShareController::class, 'store']);
    Route::delete('/notebooks/{notebook}/share/{user}', [NotebookShareController::class, 'destroy']);
    
    // Páginas dos Cadernos
    Route::get('/notebooks/{notebook_id}/pages', [PageController::class, 'index']);
    Route::post('/notebooks/{notebook_id}/pages', [PageController::class, 'store']);

    // WebRTC (Tempo Real)
    Route::post('/notebooks/{notebook_id}/webrtc/signal', [WebRtcController::class, 'signal']);
});