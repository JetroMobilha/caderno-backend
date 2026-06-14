<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Subject;
use App\Models\Notebook;

class NotebookSyncSpeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_stroke_synchronization_speed_benchmark()
    {
        // 1. Configurar o ambiente padrão
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $subject = Subject::create(['user_id' => $user->id, 'name' => 'Cálculo', 'color' => '#FFF']);
        $notebook = $subject->notebooks()->create(['title' => 'Derivadas', 'cover_type' => 'grid']);

        // Dados simulados de um traço simples
        $payload = [
            'page_number' => 1,
            'stroke_data' => [['color' => '#000', 'thickness' => 2, 'points' => [['x' => 1, 'y' => 2]]]]
        ];

        $totalRequests = 30;
        $startTime = microtime(true);

        // 2. Simular o Flutter a enviar 30 atualizações super rápidas (Stress Test)
        for ($i = 0; $i < $totalRequests; $i++) {
            $response = $this->postJson("/api/notebooks/{$notebook->id}/pages", $payload);
            $response->assertStatus(201);
        }

        $endTime = microtime(true);
        
        // 3. Calcular as métricas de tempo
        $totalTimeInSeconds = $endTime - $startTime;
        $averageTimePerRequestInMs = ($totalTimeInSeconds / $totalRequests) * 1000;

        // Imprimir o resultado no terminal para nós vermos a velocidade real
        fwrite(STDERR, "\n\n⏱️  BENCHMARK DE SINCRO: Tempo Médio por Traço: " . round($averageTimePerRequestInMs, 2) . "ms\n");

        // 4. A ASSERÇÃO DE VELOCIDADE: 
        // Garantir que a média local é inferior a 30ms. Se passar disto, o teste falha
        // porque o código estaria demasiado lento para o mercado angolano!
        $this->assertTrue($averageTimePerRequestInMs < 30, "A sincronização está demasiado lenta: {$averageTimePerRequestInMs}ms");
    }
}