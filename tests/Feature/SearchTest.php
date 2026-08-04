<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Subject;
use App\Models\Notebook;
use App\Models\Page;
use Laravel\Sanctum\Sanctum;

class SearchTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private $user;
    private $notebook;
    private $pageWithText;
    private $pageWithoutText;

    protected function setUp(): void
    {
        parent::setUp();

        // Configurar usuário e dados principais
        $this->user = User::factory()->create();
        $subject = Subject::factory()->create(['user_id' => $this->user->id]);
        $this->notebook = Notebook::factory()->create(['subject_id' => $subject->id]);

        // Página com texto pesquisável
        $this->pageWithText = Page::factory()->create([
            'notebook_id' => $this->notebook->id,
            'extracted_text' => 'O texto de teste para a busca global.',
        ]);

        // Página com texto diferente
        $this->pageWithoutText = Page::factory()->create([
            'notebook_id' => $this->notebook->id,
            'extracted_text' => 'Apenas um outro conteúdo aleatório.',
        ]);

        // Configurar um outro usuário e os seus dados para garantir o isolamento
        $otherUser = User::factory()->create();
        $otherSubject = Subject::factory()->create(['user_id' => $otherUser->id]);
        $otherNotebook = Notebook::factory()->create(['subject_id' => $otherSubject->id]);
        Page::factory()->create([
            'notebook_id' => $otherNotebook->id,
            'extracted_text' => 'Este texto pertence a outro utilizador e não deve ser encontrado.',
        ]);
    }

    /** @test */
    public function it_returns_pages_that_match_the_search_term_for_the_authenticated_user()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/search?term=global');

        $response->assertStatus(200)
                 ->assertJsonCount(1)
                 ->assertJsonFragment(['id' => $this->pageWithText->id])
                 ->assertJsonMissing(['id' => $this->pageWithoutText->id]);
    }

    /** @test */
    public function it_returns_an_empty_array_if_no_pages_match()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/search?term=termoinexistente');

        $response->assertStatus(200)
                 ->assertJsonCount(0);
    }

    /** @test */
    public function it_returns_a_bad_request_if_no_search_term_is_provided()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/search');

        $response->assertStatus(400);
    }

    /** @test */
    public function it_does_not_return_results_for_unauthenticated_users()
    {
        $response = $this->getJson('/api/search?term=global');

        $response->assertStatus(401);
    }
}
