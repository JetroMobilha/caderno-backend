<?php

namespace Tests\Unit;

use App\Models\Subject;
use App\Models\User;
use App\Models\Notebook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubjectTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_be_created_using_a_factory()
    {
        $subject = Subject::factory()->create();
        $this->assertInstanceOf(Subject::class, $subject);
    }

    /** @test */
    public function it_belongs_to_a_user()
    {
        $user = User::factory()->create();
        $subject = Subject::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $subject->user);
    }

    /** @test */
    public function it_has_many_notebooks()
    {
        $subject = Subject::factory()->create();
        Notebook::factory()->create(['subject_id' => $subject->id]);

        $this->assertInstanceOf(Notebook::class, $subject->notebooks->first());
    }

    /** @test */
    public function it_can_be_soft_deleted()
    {
        $subject = Subject::factory()->create();
        $subject->delete();

        $this->assertSoftDeleted($subject);
    }
}
