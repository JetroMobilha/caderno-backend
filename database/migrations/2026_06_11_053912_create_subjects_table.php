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
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            // Liga a disciplina ao utilizador que a criou
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); 
            $table->string('name'); // Nome da disciplina
            $table->string('color')->default('#000000'); // Cor para a UI do Flutter ficar bonita
            $table->timestamps();
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
