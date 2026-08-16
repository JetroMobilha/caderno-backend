<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collaborative_session_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('collaborative_sessions')->onDelete('cascade');
            $table->foreignId('page_id')->constrained('pages')->onDelete('cascade');
            $table->timestamps();

            // Garantir que uma página não é adicionada duas vezes à mesma sessão
            $table->unique(['session_id', 'page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaborative_session_pages');
    }
};
