<?php

namespace Tests\Unit;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_be_created_using_a_factory()
    {
        $payment = Payment::factory()->create();
        $this->assertInstanceOf(Payment::class, $payment);
    }

    /** @test */
    public function it_belongs_to_a_user()
    {
        $user = User::factory()->create();
        $payment = Payment::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $payment->user);
    }

    /** @test */
    public function it_casts_attributes_to_correct_types()
    {
        $payment = Payment::factory()->create([
            'expires_at' => now()->addMonth(),
            'paid_at' => now(),
            'amount' => 123.45,
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $payment->expires_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $payment->paid_at);
        $this->assertEquals(123.45, $payment->amount);
    }
}
