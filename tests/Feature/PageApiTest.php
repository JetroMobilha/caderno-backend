<?php

namespace Tests\Feature;

use App\Models\Notebook;
use App\Models\Subject;
use App\Models\User;
use App\Models\Page;
use App\Events\PageUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->subject = Subject::create([
            'user_id' => $this->user->id,
            'name' => 'Engenharia de Software',
        ]);
        $this->notebook = $this->subject->notebooks()->create([
            'title' => 'Apontamentos de Laravel',
        ]);
    }

    public function test_user_can_list_pages_of_their_notebook()
    {
        // Criar algumas páginas para o caderno
        Page::factory()->count(5)->create(['notebook_id' => $this->notebook->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/notebooks/{$this->notebook->id}/pages");

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data'); // O Laravel Paginate coloca os itens na chave 'data'
    }

    public function test_user_cannot_access_pages_of_others_notebook()
    {
        $outroUser = User::factory()->create();
        $outroNotebook = Notebook::factory()->create(); // Criado por outro user/subject via factory

        $response = $this->actingAs($this->user)
            ->getJson("/api/notebooks/{$outroNotebook->id}/pages");

        // Deve retornar 404 porque o user não é dono deste caderno (via hasManyThrough)
        $response->assertStatus(404);
    }

    public function test_user_can_store_and_update_page_strokes()
    {
        Event::fake();

        $payload = [
            'page_number' => 1,
            'stroke_data' => [['x' => 10, 'y' => 20, 'pressure' => 0.5]],
            'header_data' => ['title' => 'Aula 1'],
        ];

        // 1. Testar Criação
        $response = $this->actingAs($this->user)
            ->postJson("/api/notebooks/{$this->notebook->id}/pages", $payload);

        $response->assertStatus(201)
            ->assertJsonPath('stroke_data.0.x', 10);

        $this->assertDatabaseHas('pages', [
            'notebook_id' => $this->notebook->id,
            'page_number' => 1
        ]);

        // Verifica se o evento de sincronização em tempo real foi disparado
        Event::assertDispatched(PageUpdated::class, function ($event) {
            return $event->page->page_number === 1;
        });

        // 2. Testar Atualização (updateOrCreate)
        $updatePayload = [
            'page_number' => 1,
            'stroke_data' => [['x' => 50, 'y' => 50]], // Novos traços
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/notebooks/{$this->notebook->id}/pages", $updatePayload);

        $response->assertStatus(201);
        
        // Verificar se não duplicou na BD, mas sim atualizou
        $this->assertEquals(1, Page::where('notebook_id', $this->notebook->id)->count());
        
        $page = Page::first();
        $this->assertEquals(50, $page->stroke_data[0]['x']);
    }
}