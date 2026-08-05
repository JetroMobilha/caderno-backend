<?php

namespace Tests\Unit;

use App\Models\Page;
use App\Models\Notebook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_be_created_using_a_factory()
    {
        $page = Page::factory()->create();
        $this->assertInstanceOf(Page::class, $page);
    }

    /** @test */
    public function it_belongs_to_a_notebook()
    {
        $notebook = Notebook::factory()->create();
        $page = Page::factory()->create(['notebook_id' => $notebook->id]);

        $this->assertInstanceOf(Notebook::class, $page->notebook);
    }

    /** @test */
    public function it_casts_json_attributes_to_arrays()
    {
        $page = Page::factory()->create([
            'stroke_data' => ['strokes' => []],
            'text_data' => ['texts' => []],
            'ocr_data' => ['ocr' => []],
            'image_data' => ['images' => []],
            'header_data' => ['header' => []],
            'footer_data' => ['footer' => []],
        ]);

        $this->assertIsArray($page->stroke_data);
        $this->assertIsArray($page->text_data);
        $this->assertIsArray($page->ocr_data);
        $this->assertIsArray($page->image_data);
        $this->assertIsArray($page->header_data);
        $this->assertIsArray($page->footer_data);
    }

    /** @test */
    public function it_can_be_soft_deleted()
    {
        $page = Page::factory()->create();
        $page->delete();

        $this->assertSoftDeleted($page);
    }

    /** @test */
    public function merge_json_items_merges_new_items()
    {
        $oldData = [['id' => 'a', 'content' => 'old', 'updated_at' => 100]];
        $newData = [['id' => 'b', 'content' => 'new', 'updated_at' => 110]];

        $merged = Page::mergeJsonItems($oldData, $newData);

        $this->assertCount(2, $merged);
        $this->assertEquals('b', $merged[1]['id']);
    }

    /** @test */
    public function merge_json_items_updates_existing_items_with_newer_timestamp()
    {
        $oldData = [['id' => 'a', 'content' => 'old', 'updated_at' => 100]];
        $newData = [['id' => 'a', 'content' => 'new', 'updated_at' => 110]];

        $merged = Page::mergeJsonItems($oldData, $newData);

        $this->assertCount(1, $merged);
        $this->assertEquals('new', $merged[0]['content']);
    }

    /** @test */
    public function merge_json_items_does_not_update_existing_items_with_older_timestamp()
    {
        $oldData = [['id' => 'a', 'content' => 'old', 'updated_at' => 110]];
        $newData = [['id' => 'a', 'content' => 'new', 'updated_at' => 100]];

        $merged = Page::mergeJsonItems($oldData, $newData);

        $this->assertCount(1, $merged);
        $this->assertEquals('old', $merged[0]['content']);
    }

    /** @test */
    public function it_builds_ocr_text_entry_correctly()
    {
        $page = Page::factory()->create();
        $text = 'recognized text';

        $entry = $page->buildOcrTextEntry($text);

        $this->assertArrayHasKey('id', $entry);
        $this->assertEquals('ocr', $entry['type']);
        $this->assertEquals($text, $entry['text']);
        $this->assertEquals($page->id, $entry['page_id']);
    }

    /** @test */
    public function it_builds_ocr_context_correctly()
    {
        $notebook = Notebook::factory()->create();
        $page = Page::factory()->create(['notebook_id' => $notebook->id]);

        $context = $page->buildOcrContext();

        $this->assertArrayHasKey('notebook', $context);
        $this->assertArrayHasKey('subject', $context);
        $this->assertArrayHasKey('page', $context);
        $this->assertEquals($notebook->id, $context['notebook']['id']);
        $this->assertEquals($page->id, $context['page']['id']);
    }
}
