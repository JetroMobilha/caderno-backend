<?php

namespace Tests\Feature;

use App\Models\Notebook;
use App\Models\Page;
use App\Models\Subject;
use App\Services\HandwritingRecognitionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class HandwritingRecognitionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_send_image_for_handwriting_recognition(): void
    {
        $this->withoutMiddleware();

        $service = Mockery::mock(HandwritingRecognitionService::class);
        $service->shouldReceive('recognize')
            ->once()
            ->andReturn([
                'text' => 'Olá mundo',
                'engine' => 'tesseract',
                'language' => 'por',
            ]);

        $this->app->instance(HandwritingRecognitionService::class, $service);

        $response = $this->postJson('/api/handwriting/recognize', [
            'image' => UploadedFile::fake()->create('sample.png', 10, 'image/png'),
            'language' => 'por',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('text', 'Olá mundo')
            ->assertJsonPath('engine', 'tesseract');
    }

    public function test_recognized_text_is_saved_to_the_page_in_the_database(): void
    {
        $this->withoutMiddleware();

        $subject = Subject::factory()->create(['name' => 'Matemática']);
        $notebook = Notebook::factory()->create(['subject_id' => $subject->id, 'title' => 'Caderno A']);

        $service = Mockery::mock(HandwritingRecognitionService::class);
        $service->shouldReceive('recognize')
            ->once()
            ->andReturn([
                'text' => 'Texto salvo automaticamente',
                'engine' => 'tesseract',
                'language' => 'por',
            ]);

        $this->app->instance(HandwritingRecognitionService::class, $service);

        $response = $this->postJson('/api/handwriting/recognize', [
            'image' => UploadedFile::fake()->create('sample.png', 10, 'image/png'),
            'language' => 'por',
            'notebook_id' => $notebook->id,
            'page_number' => 3,
        ]);

        $response->assertOk()
            ->assertJsonPath('saved_to_database', true)
            ->assertJsonPath('notebook_id', $notebook->id);

        $page = Page::where('notebook_id', $notebook->id)->where('page_number', 3)->firstOrFail();
        $this->assertSame('Texto salvo automaticamente', $page->extracted_text);
        $this->assertSame('ocr', $page->ocr_data[0]['type']);
    }

    public function test_recognition_requires_an_image(): void
    {
        $this->withoutMiddleware();

        $response = $this->postJson('/api/handwriting/recognize', [
            'language' => 'por',
        ]);

        $response->assertStatus(422);
    }
}
