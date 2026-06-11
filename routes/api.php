<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PaymentController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});


/*
|--------------------------------------------------------------------------
| Rotas Públicas (Qualquer um pode aceder)
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);


/*
|--------------------------------------------------------------------------
| Rotas Protegidas (Só entra quem enviar o Token válido)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Devolve os dados do utilizador autenticado
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Rota para o Flutter pedir a referência do Multicaixa
    Route::post('/pay/multicaixa', [PaymentController::class, 'generateReference']);
    
    // Rotas das Disciplinas (que fizemos antes)
    Route::apiResource('subjects', App\Http\Controllers\SubjectController::class);

    // Rotas dos Cadernos (Aninhadas na Disciplina)
    Route::get('/subjects/{subject_id}/notebooks', [App\Http\Controllers\NotebookController::class, 'index']);
    Route::post('/subjects/{subject_id}/notebooks', [App\Http\Controllers\NotebookController::class, 'store']);
    // FUTURO: Aqui vão entrar as rotas de criar Cadernos, Disciplinas, etc!
});

// Rota pública para a API de pagamentos avisar o nosso servidor
Route::post('/webhooks/payment-confirmation', [PaymentController::class, 'webhookConfirmation']);