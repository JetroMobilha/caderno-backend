<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Notebook;
use App\Models\Page;

class HandwritingSynthesisApiTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_synthesize_handwriting()
    {
        $user = User::factory()->create();
        $notebook = Notebook::factory()->create(['user_id' => $user->id]);
        $page = Page::factory()->create(['notebook_id' => $notebook->id]);

        $response = $this->postJson('/api/handwriting/synthesize', [
            'text' => 'Hello, world!',
            'style' => 'cursive',
            'user_id' => $user->id,
            'notebook_id' => $notebook->id,
            'page_id' => $page->id,
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'message',
                     'image_url',
                     'svg_data',
                     'text_synthesized',
                     'style_used',
                     'metadata' => [
                         'user_id',
                         'notebook_id',
                         'page_id',
                     ],
                 ]);

        $this->assertEquals('Hello, world!', $response->json('text_synthesized'));
        $this->assertEquals('cursive', $response->json('style_used'));
        $this->assertEquals($user->id, $response->json('metadata.user_id'));
    }

    /** @test */
    public function it_returns_error_for_missing_required_fields()
    {
        $response = $this->postJson('/api/handwriting/synthesize', []);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['text', 'style', 'user_id', 'notebook_id', 'page_id']);
    }

    /** @test */
    public function it_returns_error_for_invalid_user_id()
    {
        $user = User::factory()->create();
        $notebook = Notebook::factory()->create(['user_id' => $user->id]);
        $page = Page::factory()->create(['notebook_id' => $notebook->id]);

        $response = $this->postJson('/api/handwriting/synthesize', [
            'text' => 'Hello, world!',
            'style' => 'print',
            'user_id' => 9999, // Non-existent user ID
            'notebook_id' => $notebook->id,
            'page_id' => $page->id,
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['user_id']);
    }

    /** @test */
    public function it_returns_error_for_invalid_notebook_id()
    {
        $user = User::factory()->create();
        $notebook = Notebook::factory()->create(['user_id' => $user->id]);
        $page = Page::factory()->create(['notebook_id' => $notebook->id]);

        $response = $this->postJson('/api/handwriting/synthesize', [
            'text' => 'Hello, world!',
            'style' => 'print',
            'user_id' => $user->id,
            'notebook_id' => 9999, // Non-existent notebook ID
            'page_id' => $page->id,
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['notebook_id']);
    }

    /** @test */
    public function it_returns_error_for_invalid_page_id()
    {
        $user = User::factory()->create();
        $notebook = Notebook::factory()->create(['user_id' => $user->id]);
        $page = Page::factory()->create(['notebook_id' => $notebook->id]);

        $response = $this->postJson('/api/handwriting/synthesize', [
            'text' => 'Hello, world!',
            'style' => 'print',
            'user_id' => $user->id,
            'notebook_id' => $notebook->id,
            'page_id' => 9999, // Non-existent page ID
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['page_id']);
    }
}
