<?php

namespace Tests\Feature;

use App\Models\Notebook;
use App\Models\Subject;
use App\Models\User;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotebookExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->subject = Subject::create([
            'user_id' => $this->user->id,
            'name' => 'Engenharia',
        ]);
        $this->notebook = $this->subject->notebooks()->create([
            'title' => 'Meu Caderno de Teste',
        ]);
    }

    public function test_user_can_export_their_notebook_to_pdf()
    {
        // Criamos uma página com dados para simular conteúdo
        $this->notebook->pages()->create([
            'page_number' => 1,
            'stroke_data' => ['some' => 'strokes']
        ]);

        $response = $this->actingAs($this->user)
            ->get("/api/notebooks/{$this->notebook->id}/export-pdf");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        // Verifica se o nome do ficheiro está no header de disposição
        $response->assertHeader('Content-Disposition', 'attachment; filename="Meu Caderno de Teste.pdf"');
    }

    public function test_user_cannot_export_others_notebook()
    {
        $outroUser = User::factory()->create();
        $outroNotebook = Notebook::factory()->create(); // Criado por outro user via factory

        $response = $this->actingAs($this->user)
            ->get("/api/notebooks/{$outroNotebook->id}/export-pdf");

        $response->assertStatus(404);
    }
}