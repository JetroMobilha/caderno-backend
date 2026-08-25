<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Subject;
use App\Models\Notebook;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_search_requires_authentication(): void
    {
        $response = $this->getJson('/api/search?term=test');
        $response->assertStatus(401);
    }

    public function test_global_search_requires_a_term(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson('/api/search');
        $response->assertStatus(400)
                 ->assertJson(['message' => 'O termo de pesquisa é obrigatório.']);
    }

    public function test_global_search_returns_correct_results_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $subject = Subject::factory()->create(['user_id' => $user->id]);
        $notebook = Notebook::factory()->create(['subject_id' => $subject->id]);

        $matchingPage = Page::factory()->create([
            'notebook_id' => $notebook->id,
            'extracted_text' => 'Este é um texto importante sobre inteligência artificial.'
        ]);

        $otherUser = User::factory()->create();
        $otherSubject = Subject::factory()->create(['user_id' => $otherUser->id]);
        $otherNotebook = Notebook::factory()->create(['subject_id' => $otherSubject->id]);
        $otherUserPage = Page::factory()->create([
            'notebook_id' => $otherNotebook->id,
            'extracted_text' => 'Outro utilizador também fala sobre inteligência artificial.'
        ]);

        $response = $this->actingAs($user)->getJson('/api/search?term=inteligência');

        $response->assertStatus(200)
                 ->assertJsonCount(1);

        $data = $response->json();
        $this->assertEquals($matchingPage->id, $data[0]['id']);
        $this->assertEquals($notebook->id, $data[0]['notebook']['id']);
        $this->assertEquals($subject->id, $data[0]['notebook']['subject']['id']);
    }
}
