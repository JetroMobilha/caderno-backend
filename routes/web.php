<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes (O Escudo do Flutter)
|--------------------------------------------------------------------------
*/

// Rota de salvaguarda: Qualquer link que não seja API, carrega a App Flutter!
Route::fallback(function () {
    return file_get_contents(public_path('index.html'));
});