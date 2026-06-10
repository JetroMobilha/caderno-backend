<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('payments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->onDelete('cascade');
        
        // Dados do pagamento
        $table->decimal('amount', 10, 2); // Ex: 2500.00 (Kwanzas)
        $table->string('payment_method')->default('multicaixa'); // Pode ser 'multicaixa', 'unitel_money'
        
        // Dados gerados pelo Agregador (ex: Proxypay)
        $table->string('entity')->nullable(); // Ex: A tua entidade, ex: 12345
        $table->string('reference')->nullable(); // Ex: 123 456 789
        
        // O estado da transação
        $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
        
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
