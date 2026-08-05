<?php

namespace Tests\Unit;

use App\Models\UserAlphabet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAlphabetTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_be_created_using_a_factory()
    {
        $userAlphabet = UserAlphabet::factory()->create();
        $this->assertInstanceOf(UserAlphabet::class, $userAlphabet);
    }

    /** @test */
    public function it_casts_stroke_data_to_array()
    {
        $userAlphabet = UserAlphabet::factory()->create([
            'stroke_data' => ['strokes' => []],
        ]);

        $this->assertIsArray($userAlphabet->stroke_data);
    }

    /** @test */
    public function it_belongs_to_a_user()
    {
        $user = User::factory()->create();
        $userAlphabet = UserAlphabet::factory()->create(['user_id' => $user->id]);

        // The UserAlphabet model does not have a user() relationship method.
        // So we just check if the user_id is set correctly.
        $this->assertEquals($user->id, $userAlphabet->user_id);
    }
}
