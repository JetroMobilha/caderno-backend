<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Subject;
use App\Models\Notebook;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Carbon\Carbon;

class UserTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_be_created_using_a_factory()
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(User::class, $user);
    }

    /** @test */
    public function it_hides_password_and_remember_token_when_serialized()
    {
        $user = User::factory()->create();
        $userArray = $user->toArray();

        $this->assertArrayNotHasKey('password', $userArray);
        $this->assertArrayNotHasKey('remember_token', $userArray);
    }

    /** @test */
    public function it_casts_attributes_to_correct_types()
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'pro_expires_at' => now()->addMonth(),
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $user->email_verified_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $user->pro_expires_at);
    }

    /** @test */
    public function it_hashes_password_automatically()
    {
        $password = 'password123';
        $user = User::factory()->create(['password' => $password]);

        $this->assertTrue(Hash::check($password, $user->password));
    }

    /** @test */
    public function is_pro_method_returns_true_for_pro_user()
    {
        $user = User::factory()->create([
            'pro_expires_at' => now()->addMonth(),
        ]);

        $this->assertTrue($user->isPro());
    }

    /** @test */
    public function is_pro_method_returns_false_for_non_pro_user()
    {
        $user = User::factory()->create([
            'pro_expires_at' => null,
        ]);

        $this->assertFalse($user->isPro());
    }

    /** @test */
    public function is_pro_method_returns_false_for_expired_pro_user()
    {
        $user = User::factory()->create([
            'pro_expires_at' => now()->subMonth(),
        ]);

        $this->assertFalse($user->isPro());
    }

    /** @test */
    public function it_has_many_payments()
    {
        $user = User::factory()->create();
        Payment::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(Payment::class, $user->payments->first());
    }

    /** @test */
    public function it_has_many_subjects()
    {
        $user = User::factory()->create();
        Subject::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(Subject::class, $user->subjects->first());
    }

    /** @test */
    public function it_has_many_notebooks_through_subjects()
    {
        $user = User::factory()->create();
        $subject = Subject::factory()->create(['user_id' => $user->id]);
        Notebook::factory()->create(['subject_id' => $subject->id]);

        $this->assertInstanceOf(Notebook::class, $user->notebooks->first());
    }

    /** @test */
    public function it_can_have_many_shared_notebooks()
    {
        $user = User::factory()->create();
        $notebook = Notebook::factory()->create();

        $user->sharedNotebooks()->attach($notebook->id, ['role' => 'editor']);

        $this->assertInstanceOf(Notebook::class, $user->sharedNotebooks->first());
        $this->assertEquals('editor', $user->sharedNotebooks->first()->pivot->role);
    }
}
