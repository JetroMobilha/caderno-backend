<?php

namespace Tests\Feature;

use App\Models\Notebook;
use App\Models\Page;
use App\Models\Subject;
use App\Services\HandwritingRecognitionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class HandwritingRecognitionApiTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('pages');
        Schema::dropIfExists('notebooks');
        Schema::dropIfExists('subjects');

        parent::tearDown();
    }

    public function test_user_can_send_image_for_handwriting_recognition(): void
    {
        $this->withoutMiddleware();

        $service = Mockery::mock(HandwritingRecognitionService::class);
        $service->shouldReceive('recognize')
            ->once()
            ->withArgs(function (string $path, ?string $language) {
                $this->assertNotEmpty($path);
                $this->assertSame('por', $language);

                return true;
            })
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

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('notebooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->nullable()->constrained('subjects');
            $table->string('title')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notebook_id')->constrained('notebooks');
            $table->integer('page_number');
            $table->text('extracted_text')->nullable();
            $table->json('text_data')->nullable();
            $table->json('ocr_data')->nullable();
            $table->timestamps();
        });

        $subject = Subject::create(['name' => 'Matemática']);
        $notebook = Notebook::create(['subject_id' => $subject->id, 'title' => 'Caderno A']);

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
            ->assertJsonPath('notebook_id', $notebook->id)
            ->assertJsonPath('context.notebook.title', 'Caderno A')
            ->assertJsonPath('context.subject.name', 'Matemática');

        $page = Page::where('notebook_id', $notebook->id)->where('page_number', 3)->firstOrFail();
        $this->assertSame('Texto salvo automaticamente', $page->extracted_text);
        $this->assertSame('ocr', $page->ocr_data[0]['type']);
        $this->assertSame('Matemática', $page->ocr_data[0]['context']['subject']['name']);
        $this->assertSame('Caderno A', $page->ocr_data[0]['context']['notebook']['title']);
        $this->assertSame(3, $page->ocr_data[0]['context']['page']['number']);
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
