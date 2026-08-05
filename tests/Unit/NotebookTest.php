<?php

namespace Tests\Unit;

use App\Models\Notebook;
use App\Models\Subject;
use App\Models\User;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotebookTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_be_created_using_a_factory()
    {
        $notebook = Notebook::factory()->create();
        $this->assertInstanceOf(Notebook::class, $notebook);
    }

    /** @test */
    public function it_belongs_to_a_subject()
    {
        $subject = Subject::factory()->create();
        $notebook = Notebook::factory()->create(['subject_id' => $subject->id]);

        $this->assertInstanceOf(Subject::class, $notebook->subject);
    }

    /** @test */
    public function it_has_many_pages()
    {
        $notebook = Notebook::factory()->create();
        Page::factory()->create(['notebook_id' => $notebook->id]);

        $this->assertInstanceOf(Page::class, $notebook->pages->first());
    }

    /** @test */
    public function it_can_have_many_shared_users()
    {
        $notebook = Notebook::factory()->create();
        $user = User::factory()->create();

        $notebook->sharedUsers()->attach($user->id, ['role' => 'viewer']);

        $this->assertInstanceOf(User::class, $notebook->sharedUsers->first());
        $this->assertEquals('viewer', $notebook->sharedUsers->first()->pivot->role);
    }

    /** @test */
    public function it_can_get_the_owner_id()
    {
        $user = User::factory()->create();
        $subject = Subject::factory()->create(['user_id' => $user->id]);
        $notebook = Notebook::factory()->create(['subject_id' => $subject->id]);

        $this->assertEquals($user->id, $notebook->owner_id);
    }

    /** @test */
    public function it_can_be_soft_deleted()
    {
        $notebook = Notebook::factory()->create();
        $notebook->delete();

        $this->assertSoftDeleted($notebook);
    }
}
