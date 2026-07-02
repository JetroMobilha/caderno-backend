<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use  Illuminate\Support\Facades\Storage;

class AuthController extends Controller
{
    // ISSUE 4: REGISTO DE UTILIZADOR
    public function register(Request $request)
    {
        // 1. Validar se o Flutter enviou os dados corretos
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'nullable|string|max:20|unique:users',
            'password' => 'required|string|min:8',
        ]);

        // 2. Criar o utilizador na Base de Dados (a senha é encriptada)
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone ?? null,
            'password' => Hash::make($request->password),
            // plan_type não precisa vir aqui porque na BD já definimos o default como 'basic'
        ]);

        // 3. Criar o Token de acesso seguro
        $token = $user->createToken('auth_token')->plainTextToken;

        // Dispara o e-mail de verificação em segundo plano
        //$user->sendEmailVerificationNotification();

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
            'login_id' => 'required|string', 
            'password' => 'required|string',
        ]);

        // Inteligência: Descobre se o utilizador escreveu um e-mail ou um telefone
        $loginType = filter_var($request->login_id, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        // Verifica se o email e a senha batem certo
        if (!Auth::attempt([$loginType => $request->login_id, 'password' => $request->password])) {
            return response()->json([
                'message' => 'Credenciais inválidas'
            ], 401);
        }

        // Se bater certo, procura o utilizador e gera o Token
        $user = User::where($loginType, $request->login_id)->firstOrFail();

        // Opcional: Bloquear login se o e-mail não estiver verificado
        // if (!$user->hasVerifiedEmail()) {
        //     return response()->json(['message' => 'Tens de verificar o teu e-mail primeiro.'], 403);
        // }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login efetuado com sucesso!',
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
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

    /**
     * Passo 1: O utilizador pede para recuperar a senha
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email'
        ]);

        // 1. Gera um código aleatório de 6 dígitos (ex: 482019)
        $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // 2. Guarda na base de dados (apaga o antigo se ele já tivesse pedido um antes)
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            ['token' => $code, 'created_at' => now()]
        );

        // 3. Envia o e-mail simples ao utilizador
        Mail::raw("Olá! O teu código de recuperação do Caderno Digital é: {$code}\n\nEste código expira em 15 minutos.", function ($message) use ($request) {
            $message->to($request->email)
                    ->subject('Código de Recuperação de Palavra-passe');
        });

        return response()->json(['message' => 'Enviámos um código de 6 dígitos para o teu e-mail.']);
    }

    /**
     * Passo 2: O utilizador envia o código de 6 dígitos e a nova senha
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:8|confirmed', // Exige o campo password_confirmation
        ]);

        // 1. Procura o código na base de dados
        $resetRequest = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->where('token', $request->code)
            ->first();

        // 2. Se não existir ou o código estiver errado
        if (!$resetRequest) {
            return response()->json(['message' => 'Código de recuperação inválido.'], 400);
        }

        // 3. Segurança: Verifica se o código foi pedido há mais de 15 minutos
        if (now()->diffInMinutes($resetRequest->created_at) > 15) {
            return response()->json(['message' => 'Este código expirou. Pede um novo.'], 400);
        }

        // 4. Atualiza a senha do utilizador
        $user = User::where('email', $request->email)->first();
        $user->update([
            'password' => Hash::make($request->password)
        ]);

        // 5. Destrói o código para não poder ser usado 2 vezes (Segurança Máxima)
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json(['message' => 'A tua palavra-passe foi atualizada com sucesso! Já podes fazer login.']);
    }

    public function updateProfile(Request $request) {
    $user = $request->user();
    
    $request->validate([
        'name' => 'required|string|max:255',
        'avatar' => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
    ]);

    $user->name = $request->name;

    if ($request->hasFile('avatar')) {
        // Apaga a foto antiga se existir
        if ($user->avatar) { Storage::disk('public')->delete($user->avatar); }
        
        $path = $request->file('avatar')->store('avatars', 'public');
        $user->avatar = $path;
    }

    $user->save();

    return response()->json([
        'message' => 'Perfil atualizado!',
        'user' => $user,
        'avatar_url' => $user->avatar ? asset('storage/' . $user->avatar) : null
    ]);
}
}