<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\UserAlphabet;
use Illuminate\Support\Facades\Auth;

class UserAlphabetController extends Controller
{
    /**
     * Armazena a definição de um novo caractere para o utilizador autenticado.
     * Ou atualiza um já existente.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'character'   => 'required|string|max:10',
            'stroke_data' => 'required|array' // Valida que os dados dos traços são um array
        ]);

        $user = Auth::user();

        // Cria ou atualiza a entrada para este utilizador e caractere
        $alphabetEntry = UserAlphabet::updateOrCreate(
            [
                'user_id'   => $user->id,
                'character' => $validatedData['character']
            ],
            [
                'stroke_data' => $validatedData['stroke_data'] // Laravel trata a conversão para JSON
            ]
        );

        return response()->json([
            'message' => 'Caractere guardado com sucesso!',
            'data'    => $alphabetEntry
        ], 200); // 200 OK porque pode ser uma criação ou uma atualização
    }
}
