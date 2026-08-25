<?php

namespace Tests\Feature;

use App\Models\Notebook;
use App\Models\Subject;
use App\Models\User;
use App\Models\Page;
use App\Events\PageUpdated;
use App\Jobs\ProcessPageOcr; // 🚀 Adicionado
use App\Services\HandwritingRecognitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Bus; // 🚀 Adicionado
use Mockery;
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
        Page::factory()->count(5)->create(['notebook_id' => $this->notebook->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/notebooks/{$this->notebook->id}/pages");

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data');
    }

    public function test_user_cannot_access_pages_of_others_notebook()
    {
        $outroUser = User::factory()->create();
        $outroNotebook = Notebook::factory()->create();

        $response = $this->actingAs($this->user)
            ->getJson("/api/notebooks/{$outroNotebook->id}/pages");

        $response->assertStatus(404);
    }

    public function test_user_can_store_and_update_page_strokes()
    {
        Event::fake();

        $payload = [
            'client_id' => 'test-id',
            'page_number' => 1,
            'stroke_data' => [['x' => 10, 'y' => 20]],
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/notebooks/{$this->notebook->id}/pages", $payload);

        $response->assertStatus(201);

        Event::assertDispatched(PageUpdated::class, function ($event) {
            return $event->page->notebook_id === $this->notebook->id;
        });

        $updatePayload = [
            'client_id' => 'test-id',
            'page_number' => 1,
            'stroke_data' => [['x' => 50, 'y' => 50]],
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/notebooks/{$this->notebook->id}/pages", $updatePayload);

        $response->assertStatus(201);
    }

    public function test_sync_push_can_recognize_strokes_and_store_text()
    {
        Bus::fake(); // 🚀 Interceptar o Job de OCR

        $response = $this->actingAs($this->user)
            ->postJson('/api/sync/pages/push', [
                'pages' => [[
                    'client_id' => 'sync-id',
                    'notebook_id' => $this->notebook->id,
                    'page_number' => 1,
                    'stroke_data' => [['points' => [['x' => 10, 'y' => 20]]]],
                ]],
            ]);

        $response->assertOk();

        // Verificar se o Job foi enviado para a fila
        Bus::assertDispatched(ProcessPageOcr::class);
    }
}
