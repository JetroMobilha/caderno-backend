<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Subject;

class SubjectController extends Controller
{
    // Listar todas as disciplinas do utilizador
    public function index(Request $request)
    {
        return response()->json($request->user()->subjects);
    }

    // Criar uma nova disciplina (É isto que o nosso teste vai validar!)
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:7',
        ]);

        $subject = $request->user()->subjects()->create([
            'name' => $request->name,
            'color' => $request->color ?? '#000000',
        ]);

        return response()->json($subject, 201);
    }
}