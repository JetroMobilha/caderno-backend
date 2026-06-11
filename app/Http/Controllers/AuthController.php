<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    // ISSUE 4: REGISTO DE UTILIZADOR
    public function register(Request $request)
    {
        // 1. Validar se o Flutter enviou os dados corretos
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        // 2. Criar o utilizador na Base de Dados (a senha é encriptada)
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            // plan_type não precisa vir aqui porque na BD já definimos o default como 'basic'
        ]);

        // 3. Criar o Token de acesso seguro
        $token = $user->createToken('auth_token')->plainTextToken;

        // 4. Devolver a resposta em JSON para o Flutter
        return response()->json([
            'message' => 'Utilizador criado com sucesso!',
            'user' => $user,
            'token' => $token
        ], 201);
    }

    // ISSUE 5: LOGIN
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Verifica se o email e a senha batem certo
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Credenciais inválidas'
            ], 401);
        }

        // Se bater certo, procura o utilizador e gera o Token
        $user = User::where('email', $request->email)->firstOrFail();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login efetuado com sucesso!',
            'user' => $user,
            'token' => $token
        ], 200);
    }

    // BÓNUS: LOGOUT
    public function logout(Request $request)
    {
        // Destrói o token atual do utilizador
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Sessão terminada com sucesso'
        ]);
    }
}