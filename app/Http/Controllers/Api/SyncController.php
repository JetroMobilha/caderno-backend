<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subject;  

class SyncController extends Controller
{
    public function push(Request $request)
    {
        $user = $request->user();
        
        // Recebe o batalhão de disciplinas do telemóvel
        $clientSubjects = $request->input('subjects', []);
        
        $syncedSubjects = [];

        foreach ($clientSubjects as $subjectData) {
            
            // O updateOrCreate procura pelo server_id. Se existir, atualiza. Se for nulo, cria uma nova.
            $subject = Subject::updateOrCreate(
                [
                    'id' => $subjectData['server_id'], // Se for NULL, o Laravel sabe que é novo
                    'user_id' => $user->id
                ],
                [
                    'name' => $subjectData['name'],
                    'color' => $subjectData['color'],
                    'icon' => $subjectData['icon'],
                ]
            );

            // Prepara a resposta para o telemóvel saber quem foi guardado
            $syncedSubjects[] = [
                'client_id' => $subjectData['id'], // O ID local do SQLite
                'server_id' => $subject->id,       // O ID oficial da Nuvem
            ];
        }

        return response()->json([
            'message' => 'Sincronização Push concluída com sucesso!',
            'synced_subjects' => $syncedSubjects
        ]);
    }
 
    public function pull(Request $request)
    {
        $user = $request->user();
        
        // 🚀 O RADAR: Captura a data do último rastreio que o telemóvel enviou
        $lastSyncedAt = $request->query('last_synced_at');

        $query = Subject::where('user_id', $user->id);

        if ($lastSyncedAt) {
            // 🎯 Filtra APENAS o que foi criado ou alterado DEPOIS daquele segundo!
            $query->where('updated_at', '>', $lastSyncedAt);
        }

        $subjects = $query->get();

        return response()->json([
            'message' => 'Rastreio concluído.',
            'subjects' => $subjects,
            // 🚀 Envia a hora ATUAL do servidor para o telemóvel guardar para o próximo rastreio
            'server_time' => now()->toIso8601String() 
        ]);
    }
}